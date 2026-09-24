<?php

namespace App\Service\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Support\Parametres;

/**
 * Consommation réelle, tirée de JOURNAL_REPAS, par aliment et par recette.
 *
 * Source unique pour le Prévisionnel (R13), l'Agriculture (R18), la jauge du Stock (R12) et les
 * statistiques (R14, R15) : les pages affichent ainsi les mêmes chiffres pour un même aliment.
 */
class ConsommationCalculator
{
    public function __construct(
        private readonly BesoinsCalculator $besoins,
        private readonly RecetteCalculator $recettes,
        private readonly StockCalculator $stock,
    ) {
    }

    /**
     * Fenêtre [du, au] des `$jours` derniers jours, aujourd'hui inclus.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function fenetre(DonneesVaisseau $d, int $jours): array
    {
        return [$d->aujourdhui->modify(sprintf('-%d days', max(1, $jours) - 1)), $d->aujourdhui];
    }

    /**
     * Lignes du journal dont le jour est dans [du, au] (dates incluses).
     *
     * @return list<array<string, mixed>>
     */
    public static function lignes(DonneesVaisseau $d, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $du = $du->setTime(0, 0);
        $au = $au->setTime(0, 0);

        return array_values(array_filter($d->journal, static function (array $l) use ($du, $au) {
            $jour = $l['date_heure']->setTime(0, 0);

            return $jour >= $du && $jour <= $au;
        }));
    }

    /** Grammes de recette consommés par une ligne du journal (part standard du créneau si portion_g est vide). */
    public function grammesRecette(DonneesVaisseau $d, array $ligne): float
    {
        if (null !== $ligne['portion_g']) {
            return $ligne['portion_g'];
        }
        $recette = $d->recettes[$ligne['recette_id']] ?? null;
        $equipier = $d->equipages[$ligne['equipage_id']] ?? null;
        if (null === $recette || null === $equipier) {
            return 0.0;
        }

        return RecetteCalculator::portionG(
            $recette,
            $d->typesRepas[$ligne['type_repas_id']]['cle'] ?? '',
            $this->besoins->besoinIndividuel($equipier)['energy_kcal'],
        );
    }

    /**
     * Grammes consommés par aliment sur [du, au] : grammes de recette × composition de la recette.
     *
     * @return array<string, float>
     */
    public function parAliment(DonneesVaisseau $d, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $r = [];
        foreach (self::lignes($d, $du, $au) as $ligne) {
            $recette = $d->recettes[$ligne['recette_id']] ?? null;
            if (null === $recette) {
                continue;
            }
            $grammes = $this->grammesRecette($d, $ligne);
            foreach ($this->recettes->composition($d, $recette)['aliments'] as $code => $g100) {
                $r[$code] = ($r[$code] ?? 0.0) + $grammes * $g100 / 100;
            }
        }

        return $r;
    }

    /**
     * Nombre de fois où chaque recette a été servie sur [du, au].
     *
     * @return array<int, int>
     */
    public static function parRecette(DonneesVaisseau $d, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $r = [];
        foreach (self::lignes($d, $du, $au) as $ligne) {
            $r[$ligne['recette_id']] = ($r[$ligne['recette_id']] ?? 0) + 1;
        }

        return $r;
    }

    /**
     * Consommation journalière moyenne par aliment sur les `$jours` derniers jours.
     *
     * @return array<string, float>
     */
    public function moyenneJournaliere(DonneesVaisseau $d, int $jours): array
    {
        [$du, $au] = self::fenetre($d, $jours);

        return array_map(static fn (float $g) => $g / max(1, $jours), $this->parAliment($d, $du, $au));
    }

    /**
     * Stock consommable par aliment (réserve courante, lots non périmés) : ce sur quoi portent
     * l'autonomie et la production à prévoir. Les réserves de sécurité ne comptent pas.
     *
     * @return array<string, float>
     */
    public function stockConsommable(DonneesVaisseau $d): array
    {
        return $this->stock->disponible($d, $d->aujourdhui, Parametres::RESERVE_UTILISABLE);
    }

    /** Jours d'autonomie d'un stock pour une consommation journalière donnée, null si rien n'est consommé. */
    public static function joursAutonomie(float $stockG, float $consoJourG): ?float
    {
        return $consoJourG > 0 ? $stockG / $consoJourG : null;
    }
}
