<?php

namespace App\Service\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Donnees\NutrixDataProvider;
use App\Service\Moteur\Exception\MoteurException;

/**
 * Point d'entrée du moteur de calcul pour les routes métier : une méthode par endpoint du README.
 *
 * Chaque méthode charge les données, appelle le calculateur concerné et renvoie un tableau prêt à
 * être sérialisé en JSON. Les erreurs métier sont levées en MoteurException (code HTTP inclus).
 *
 *   GET  /api/equipages/{id}/besoins                  besoinEquipier()
 *   GET  /api/equipages/besoins?date=                 besoinEquipe()
 *   GET  /api/equipages/besoins/creneau               besoinCreneau()
 *   GET  /api/equipages/{id}/ecart-nutritionnel       ecartNutritionnel()
 *   GET  /api/recettes/{id}/profil-nutritionnel       profilRecette()
 *   POST /api/planning-repas/simuler                  simulerPlanning()
 *   POST /api/planning-repas/generer                  genererPlanning()
 *   GET  /api/planning-repas                          lirePlanning()
 *   POST /api/journal-repas                           enregistrerRepas() (via FrontController)
 *   GET  /api/stock/autonomie                         autonomie()
 *   GET  /api/stock/previsions?semaines=8             previsions()
 *   GET  /api/stock/besoins-agricoles?semaine=        besoinsAgricoles()
 */
class MoteurCalcul
{
    public function __construct(
        private readonly NutrixDataProvider $donnees,
        private readonly BesoinsCalculator $besoins,
        private readonly RecetteCalculator $recettes,
        private readonly StockCalculator $stock,
        private readonly PlanningCalculator $planning,
        private readonly EcartCalculator $ecarts,
        private readonly PrevisionCalculator $previsions,
    ) {
    }

    public function besoinEquipier(int $id, ?float $lpiMg = null, ?string $statutMenopause = null): array
    {
        return $this->besoins->besoinEquipier($this->charger(), $id, $lpiMg, $statutMenopause);
    }

    public function besoinEquipe(?string $date = null): array
    {
        $d = $this->charger();

        return $this->besoins->besoinEquipe($d, self::date($date) ?? $d->aujourdhui);
    }

    public function besoinCreneau(?string $date, string $typeRepas): array
    {
        $d = $this->charger();

        return $this->besoins->besoinCreneau($d, self::date($date) ?? $d->aujourdhui, $typeRepas);
    }

    public function ecartNutritionnel(int $id, int $periodeJours = 7): array
    {
        return $this->ecarts->ecart($this->charger(), $id, $periodeJours);
    }

    public function profilRecette(int $id): array
    {
        return $this->recettes->profilNutritionnel($this->charger(), $id);
    }

    public function simulerPlanning(array $entree): array
    {
        return $this->planning->simuler($this->charger(), $entree);
    }

    /** Génère le planning puis l'enregistre (sauf `"enregistrer": false` dans l'entrée). */
    public function genererPlanning(array $entree): array
    {
        $d = $this->charger();
        $resultat = $this->planning->generer($d, $entree);
        $repas = $resultat['repas_a_enregistrer'];
        unset($resultat['repas_a_enregistrer']);

        $resultat['planning_repas_ids'] = [];
        $resultat['occupants_enregistres'] = false;
        if ($entree['enregistrer'] ?? true) {
            $resultat['planning_repas_ids'] = $this->donnees->enregistrerPlanning($repas, $d->planningOccupantLie);
            $resultat['occupants_enregistres'] = $d->planningOccupantLie;
            if (!$d->planningOccupantLie) {
                $resultat['alertes'][] = ['niveau' => 'info', 'type' => 'schema',
                    'message' => 'Parts par occupant non enregistrées : PLANNING_REPAS_OCCUPANT n\'a pas de colonne Id_PLANNING_REPAS (gap #10)'];
            }
        }

        return $resultat;
    }

    public function lirePlanning(?string $dateDebut, ?string $dateFin, ?int $equipageId): array
    {
        return $this->planning->lire($this->charger(), $dateDebut, $dateFin, $equipageId);
    }

    /**
     * Enregistre un repas consommé et renvoie son apport. Avec `"sortie_stock": true`, les ingrédients
     * sont aussi sortis du stock en FEFO.
     */
    public function enregistrerRepas(array $entree): array
    {
        $d = $this->charger();
        $repas = $this->planning->repasJournal($d, $entree);
        $id = $this->donnees->enregistrerJournal($repas['equipage_id'], $repas['recette_id'], $repas['type_repas_id'], $repas['date_heure'], $repas['portion_g']);

        $sorties = [];
        if ($entree['sortie_stock'] ?? false) {
            $recette = $d->recettes[$repas['recette_id']];
            foreach ($this->recettes->composition($d, $recette)['aliments'] as $code => $g100) {
                // Un ingrédient à 0 g dans la recette ne sort rien (planifierSortie refuse une quantité nulle,
                // et le repas est déjà enregistré à ce stade).
                if ($g100 <= 0) {
                    continue;
                }
                $plan = $this->stock->planifierSortie($d, $code, $repas['portion_g'] * $g100 / 100, $repas['date_heure']);
                $this->donnees->enregistrerSorties($plan['sorties'], $recette['id'], $repas['date_heure']);
                $sorties[] = ['aliment_id' => $code] + $plan;
            }
        }

        return [
            'id' => $id,
            'equipage_id' => $repas['equipage_id'],
            'recette_id' => $repas['recette_id'],
            'type_repas_id' => $repas['type_repas_id'],
            'date_heure' => $repas['date_heure']->format('Y-m-d\TH:i:s'),
            'portion_g' => $repas['portion_g'],
            'apport' => $repas['apport'],
        ] + ($sorties ? ['sorties_stock' => $sorties] : []);
    }

    public function autonomie(): array
    {
        return $this->stock->autonomie($this->charger());
    }

    public function previsions(int $semaines = 8): array
    {
        return $this->previsions->previsions($this->charger(), $semaines);
    }

    public function besoinsAgricoles(?int $semaine = null, int $semaines = 8): array
    {
        return $this->previsions->besoinsAgricoles($this->charger(), $semaine, $semaines);
    }

    private function charger(): DonneesVaisseau
    {
        return $this->donnees->charger();
    }

    private static function date(?string $valeur): ?\DateTimeImmutable
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return false !== $date ? $date : throw MoteurException::invalide('date doit être au format YYYY-MM-DD');
    }
}
