<?php

namespace App\Service\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\Support\Nutriments;
use App\Service\Moteur\Support\Parametres;

/**
 * Recettes : profil nutritionnel (§4), composition en aliments, allergènes et créneaux (§5).
 */
class RecetteCalculator
{
    public const MODE_NOMINAL = 'nominal';
    public const MODE_EMPIRIQUE = 'estimation_empirique';
    public const MODE_UNIFORME = 'repartition_uniforme';

    /** @var \WeakMap<DonneesVaisseau, array<int, array{mode: string, aliments: array<string, float>}>> */
    private \WeakMap $compositions;

    public function __construct()
    {
        $this->compositions = new \WeakMap();
    }

    /**
     * Apport de `$grammes` d'une recette, à partir de ses valeurs stockées pour 100 g (source de vérité, §4).
     *
     * @return array<string, float>
     */
    public function apport(array $recette, float $grammes): array
    {
        return Nutriments::pourGrammes($recette['pour100g'], $grammes);
    }

    /**
     * Taille d'une part (g) : la quantité de recette qui apporte l'énergie du créneau pour une personne,
     * bornée entre PORTION_G_MIN et PORTION_G_MAX (une infusion à 5 kcal/100 g ne doit pas donner 5 kg).
     */
    public static function portionG(array $recette, string $typeRepasCle, float $energieJourPersonneKcal): float
    {
        $kcal100g = $recette['pour100g']['kcal'];
        $coefficient = Parametres::COEFFICIENTS_CRENEAU[$typeRepasCle] ?? 0.25;
        if ($kcal100g <= 0) {
            return Parametres::PORTION_G_MIN;
        }

        return max(Parametres::PORTION_G_MIN, min(Parametres::PORTION_G_MAX, $energieJourPersonneKcal * $coefficient / $kcal100g * 100));
    }

    /**
     * Profil recalculé depuis les ingrédients, pour 100 g de recette. Null si les quantités sont toutes à 0.
     *
     * @return array<string, float>|null
     */
    public function profilRecalcule(DonneesVaisseau $d, array $recette): ?array
    {
        $masse = array_sum($recette['ingredients']);
        if ($masse <= 0) {
            return null;
        }
        $total = Nutriments::zero();
        foreach ($recette['ingredients'] as $code => $grammes) {
            if (isset($d->aliments[$code])) {
                $total = Nutriments::ajouter($total, Nutriments::pourGrammes($d->aliments[$code]['pour100g'], $grammes));
            }
        }

        return Nutriments::multiplier($total, 100 / $masse);
    }

    /** GET /api/recettes/{id}/profil-nutritionnel */
    public function profilNutritionnel(DonneesVaisseau $d, int $recetteId): array
    {
        $recette = $d->recettes[$recetteId] ?? throw MoteurException::introuvable(sprintf('Recette %d introuvable', $recetteId));
        $recalcule = $this->profilRecalcule($d, $recette);

        $r = [
            'recette_id' => $recetteId,
            'libelle' => $recette['libelle'],
            'poids_total_g' => $recette['poids_total_g'],
            'base' => '100 g',
            'valeur_stockee' => Nutriments::arrondir($recette['pour100g']),
            'valeur_recalculee_ingredients' => $recalcule ? Nutriments::arrondir($recalcule) : null,
            'recompute_disponible' => null !== $recalcule,
            'ecart_pct' => null,
            'ecart_pct_max' => null,
        ];

        if (null === $recalcule) {
            $r['warning'] = 'recette_ingredient.quantite_g = 0 pour tous les ingrédients (données legacy incomplètes)';

            return $r;
        }

        $ecarts = [];
        foreach (Nutriments::CLES as $cle) {
            $stocke = $recette['pour100g'][$cle];
            $ecarts[$cle] = $stocke > 0 ? round(100 * ($recalcule[$cle] - $stocke) / $stocke, 1) : null;
        }
        $r['ecart_pct'] = $ecarts;
        $valeurs = array_map('abs', array_filter($ecarts, static fn ($e) => null !== $e));
        $r['ecart_pct_max'] = $valeurs ? max($valeurs) : 0.0;

        return $r;
    }

    /**
     * Grammes de chaque aliment pour 100 g de recette.
     *
     *  - nominal : quantités de recette_ingredient (§11.1) ;
     *  - estimation_empirique : proportions des sorties de stock liées à la recette via Asso_11 (§11.2) ;
     *  - repartition_uniforme : dernier recours, masse partagée à parts égales entre les ingrédients.
     *
     * @return array{mode: string, aliments: array<string, float>}
     */
    public function composition(DonneesVaisseau $d, array $recette): array
    {
        $cache = $this->compositions[$d] ?? [];
        if (isset($cache[$recette['id']])) {
            return $cache[$recette['id']];
        }
        $resultat = $this->calculerComposition($d, $recette);
        $cache[$recette['id']] = $resultat;
        $this->compositions[$d] = $cache;

        return $resultat;
    }

    /** @return array{mode: string, aliments: array<string, float>} */
    private function calculerComposition(DonneesVaisseau $d, array $recette): array
    {
        $ingredients = $recette['ingredients'];
        $masse = array_sum($ingredients);
        if ($masse > 0) {
            return [
                'mode' => self::MODE_NOMINAL,
                'aliments' => array_map(static fn ($g) => 100 * $g / $masse, $ingredients),
            ];
        }

        $lots = array_column($d->lots, 'aliment', 'id');
        $sorties = [];
        foreach ($d->mouvements as $m) {
            if (!in_array($recette['id'], $m['recettes'], true) || !str_starts_with($m['type'], 'sortie')) {
                continue;
            }
            $aliment = $lots[$m['lot_id']] ?? null;
            if (null !== $aliment && array_key_exists($aliment, $ingredients)) {
                $sorties[$aliment] = ($sorties[$aliment] ?? 0.0) + abs($m['quantite_g']);
            }
        }
        $totalSorties = array_sum($sorties);
        if ($totalSorties > 0) {
            return [
                'mode' => self::MODE_EMPIRIQUE,
                'aliments' => array_map(static fn ($g) => 100 * $g / $totalSorties, $sorties),
            ];
        }

        $n = count($ingredients);

        return [
            'mode' => self::MODE_UNIFORME,
            'aliments' => $n > 0 ? array_fill_keys(array_keys($ingredients), 100.0 / $n) : [],
        ];
    }

    /**
     * Allergènes contenus dans une recette (ids ALLERGENE).
     *
     * @return list<int>
     */
    public function allergenes(DonneesVaisseau $d, array $recette): array
    {
        $ids = [];
        foreach (array_keys($recette['ingredients']) as $code) {
            foreach ($this->allergenesAliment($d, $code) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return list<int> */
    public function allergenesAliment(DonneesVaisseau $d, string $code): array
    {
        if (null !== $d->alimentAllergenes) {
            return $d->alimentAllergenes[$code] ?? [];
        }

        // Repli tant que ALIMENT_ALLERGENE n'existe pas : déduction depuis le code aliment.
        $segments = explode('_', $code);
        $ids = [];
        foreach ($d->allergenes as $id => $allergene) {
            foreach (Parametres::ALLERGENES_PAR_MOT_CLE[$allergene['cle']] ?? [] as $motif) {
                if (self::codeCorrespond($code, $segments, $motif)) {
                    $ids[] = $id;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Créneaux (clés normalisées) où la recette peut être servie. Vide = pas un repas.
     *
     * @return list<string>
     */
    public function creneaux(DonneesVaisseau $d, array $recette): array
    {
        if (in_array($recette['categorie'], Parametres::CATEGORIES_RECETTE_NON_REPAS, true)) {
            return [];
        }
        if (null !== $d->recetteCreneaux) {
            return $d->recetteCreneaux[$recette['id']] ?? [];
        }

        return Parametres::CRENEAUX_PAR_CATEGORIE[$recette['categorie']] ?? [];
    }

    /** @param list<string> $segments */
    private static function codeCorrespond(string $code, array $segments, string $motif): bool
    {
        if (str_starts_with($motif, '=')) {
            return $code === substr($motif, 1);
        }
        $mots = explode('_', $motif);
        $n = count($mots);
        for ($i = 0; $i + $n <= count($segments); ++$i) {
            $ok = true;
            foreach ($mots as $j => $mot) {
                if (!str_starts_with($segments[$i + $j], $mot)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
