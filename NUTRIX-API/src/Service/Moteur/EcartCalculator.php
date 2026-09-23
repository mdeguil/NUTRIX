<?php

namespace App\Service\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\Support\Nutriments;
use App\Service\Moteur\Support\Parametres;

/**
 * Écart entre apports réels (JOURNAL_REPAS) et besoins, et rétroaction sur le planning (MOTEUR_CALCUL.md §13).
 */
class EcartCalculator
{
    public function __construct(
        private readonly BesoinsCalculator $besoins,
        private readonly RecetteCalculator $recettes,
    ) {
    }

    /** Apport d'une ligne du journal. Sans portion_g, on retient la part standard du créneau. */
    public function apportJournal(DonneesVaisseau $d, array $ligne, float $energieJourKcal): array
    {
        $recette = $d->recettes[$ligne['recette_id']] ?? null;
        if (null === $recette) {
            return Nutriments::zero();
        }
        $grammes = $ligne['portion_g'] ?? RecetteCalculator::portionG($recette, $d->typesRepas[$ligne['type_repas_id']]['cle'] ?? '', $energieJourKcal);

        return $this->recettes->apport($recette, $grammes);
    }

    /**
     * Apport réel jour par jour d'un équipier sur [du, au] (dates incluses).
     *
     * @return array<string, array{apport: array<string, float>, nb_repas: int}> indexé par date Y-m-d
     */
    public function apportsParJour(DonneesVaisseau $d, int $equipageId, \DateTimeImmutable $du, \DateTimeImmutable $au, float $energieJourKcal): array
    {
        $jours = [];
        foreach ($d->journal as $ligne) {
            $jour = $ligne['date_heure']->setTime(0, 0);
            if ($ligne['equipage_id'] !== $equipageId || $jour < $du || $jour > $au) {
                continue;
            }
            $cle = $jour->format('Y-m-d');
            $jours[$cle] ??= ['apport' => Nutriments::zero(), 'nb_repas' => 0];
            $jours[$cle]['apport'] = Nutriments::ajouter($jours[$cle]['apport'], $this->apportJournal($d, $ligne, $energieJourKcal));
            ++$jours[$cle]['nb_repas'];
        }
        ksort($jours);

        return $jours;
    }

    /** GET /api/equipages/{id}/ecart-nutritionnel?periode_jours=7 */
    public function ecart(DonneesVaisseau $d, int $equipageId, int $periodeJours = 7): array
    {
        $eq = $d->equipages[$equipageId] ?? throw MoteurException::introuvable(sprintf('Équipage %d introuvable', $equipageId));
        if ($periodeJours < 1 || $periodeJours > 365) {
            throw MoteurException::invalide('periode_jours doit être compris entre 1 et 365');
        }
        $au = $d->aujourdhui;
        $du = $au->modify(sprintf('-%d days', $periodeJours - 1));

        $besoin = $this->besoins->besoinIndividuel($eq);
        $besoinMacros = Nutriments::depuisBesoin($besoin);
        $jours = $this->apportsParJour($d, $equipageId, $du, $au, $besoin['energy_kcal']);

        $total = Nutriments::zero();
        foreach ($jours as $j) {
            $total = Nutriments::ajouter($total, $j['apport']);
        }
        $moyen = Nutriments::multiplier($total, 1 / $periodeJours);
        $ecart = Nutriments::ajouter($moyen, $besoinMacros, -1);
        $ecartPct = [];
        foreach (Nutriments::CLES as $cle) {
            $ecartPct[$cle] = $besoinMacros[$cle] > 0 ? round(100 * $ecart[$cle] / $besoinMacros[$cle], 1) : null;
        }

        $retroaction = [];
        foreach ($this->seriesDeficit($jours, $besoinMacros) as $cle => $serie) {
            if ($serie >= Parametres::RETROACTION_JOURS_CONSECUTIFS) {
                $retroaction[$cle] = [
                    'poids_majore' => true,
                    'facteur' => Parametres::RETROACTION_FACTEUR,
                    'raison' => sprintf('apport sous le besoin %d jours journalisés consécutifs', $serie),
                ];
            }
        }

        return [
            'equipage_id' => $equipageId,
            'periode_jours' => $periodeJours,
            'du' => $du->format('Y-m-d'),
            'au' => $au->format('Y-m-d'),
            'jours_journalises' => count($jours),
            'consomme_moyen_jour' => Nutriments::arrondir($moyen),
            'besoin_moyen_jour' => BesoinsCalculator::formater($besoin),
            'ecart' => Nutriments::arrondir($ecart),
            'ecart_pct' => $ecartPct,
            'par_jour' => array_map(static fn ($date, $j) => [
                'date' => $date, 'nb_repas' => $j['nb_repas'], 'apport' => Nutriments::arrondir($j['apport']),
                'couverture_pct' => Nutriments::couverture($j['apport'], $besoinMacros),
            ], array_keys($jours), $jours),
            'retroaction_appliquee' => $retroaction ?: new \stdClass(),
            'meta' => [
                'hypotheses' => array_merge(
                    ['moyenne calculée sur toute la période, jours sans repas journalisé comptés à 0'],
                    0 === count($jours) ? ['aucun repas journalisé sur la période'] : []
                ),
            ],
        ];
    }

    /**
     * Poids de chaque nutriment dans le score nutrition d'un groupe (§13 → §8.1) : majoré si un
     * membre du groupe reste en déficit plusieurs jours journalisés de suite sur les 7 derniers jours.
     *
     * @param list<int> $equipageIds
     *
     * @return array<string, float>
     */
    public function poidsNutriments(DonneesVaisseau $d, array $equipageIds, \DateTimeImmutable $date): array
    {
        $poids = array_fill_keys(Nutriments::CLES, 1.0);
        foreach ($equipageIds as $id) {
            if (!isset($d->equipages[$id])) {
                continue;
            }
            $besoin = $this->besoins->besoinIndividuel($d->equipages[$id]);
            $jours = $this->apportsParJour($d, $id, $date->modify('-7 days'), $date->modify('-1 day'), $besoin['energy_kcal']);
            foreach ($this->seriesDeficit($jours, Nutriments::depuisBesoin($besoin)) as $cle => $serie) {
                if ($serie >= Parametres::RETROACTION_JOURS_CONSECUTIFS) {
                    $poids[$cle] = Parametres::RETROACTION_FACTEUR;
                }
            }
        }

        return $poids;
    }

    /**
     * Plus longue série de jours journalisés consécutifs (dates qui se suivent) où l'apport est sous le besoin.
     *
     * @param array<string, array{apport: array<string, float>, nb_repas: int}> $jours
     * @param array<string, float>                                             $besoin
     *
     * @return array<string, int>
     */
    private function seriesDeficit(array $jours, array $besoin): array
    {
        $max = array_fill_keys(Nutriments::CLES, 0);
        $courant = array_fill_keys(Nutriments::CLES, 0);
        $precedent = null;
        foreach ($jours as $date => $j) {
            $jour = new \DateTimeImmutable($date);
            $consecutif = null !== $precedent && 1 === (int) $precedent->diff($jour)->format('%a');
            foreach (Nutriments::CLES as $cle) {
                if ($j['apport'][$cle] < $besoin[$cle]) {
                    $courant[$cle] = $consecutif ? $courant[$cle] + 1 : 1;
                } else {
                    $courant[$cle] = 0;
                }
                $max[$cle] = max($max[$cle], $courant[$cle]);
            }
            $precedent = $jour;
        }

        return $max;
    }
}
