<?php

namespace App\Service\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\Support\EfsaReference;
use App\Service\Moteur\Support\Nutriments;
use App\Service\Moteur\Support\Parametres;
use App\Service\Moteur\Support\Texte;

/**
 * Besoins nutritionnels (MOTEUR_CALCUL.md §2 et §3).
 *
 * Produit le format BesoinNutritionnel d'API_BESOINS_PLANNING.md §2. Les valeurs internes
 * ne sont pas arrondies (pour pouvoir être sommées) ; `formater()` arrondit pour la sortie.
 */
class BesoinsCalculator
{
    /** Clé de sortie → [chemin du bloc EFSA, liste à lire]. */
    private const MINERAUX = [
        'calcium_mg' => ['minerals.calcium', 'values'],
        'iron_mg' => ['minerals.iron', 'pri'],
        'zinc_mg' => ['minerals.zinc', 'values'],
        'fluoride_mg' => ['minerals.fluoride_ai_mg_per_day', 'values'],
        'iodine_ug' => ['minerals.iodine_pri_ug_per_day', 'values'],
        'manganese_mg' => ['minerals.manganese_ai_mg_per_day', 'values'],
        'molybdenum_ug' => ['minerals.molybdenum_pri_ug_per_day', 'values'],
        'phosphorus_mg' => ['minerals.phosphorus_pri_mg_per_day', 'values'],
        'potassium_mg' => ['minerals.potassium_ai_mg_per_day', 'values'],
        'selenium_ug' => ['minerals.selenium_pri_ug_per_day', 'values'],
        'copper_mg' => ['minerals.copper_ai_mg_per_day', 'values'],
        'magnesium_mg' => ['minerals.magnesium_ai_mg_per_day', 'values'],
    ];

    /** Niacine et thiamine sont exprimées par MJ : traitées à part. */
    private const VITAMINES = [
        'folate_ug' => ['vitamins.folate_ug_dfe_per_day', 'pri'],
        'niacin_mg' => null,
        'riboflavin_mg' => ['vitamins.riboflavin_mg_per_day', 'pri'],
        'thiamin_mg' => null,
        'vitamin_a_ug' => ['vitamins.vitamin_a_ug_re_per_day', 'pri'],
        'vitamin_b6_mg' => ['vitamins.vitamin_b6_mg_per_day', 'pri'],
        'vitamin_c_mg' => ['vitamins.vitamin_c_mg_per_day', 'pri'],
        'vitamin_e_mg' => ['vitamins.alpha_tocopherol_vitamin_e_mg_per_day_ai', 'values'],
        'biotin_ug' => ['vitamins.biotin_ug_per_day_ai', 'values'],
        'choline_mg' => ['vitamins.choline_mg_per_day_ai', 'values'],
        'vitamin_b12_ug' => ['vitamins.cobalamin_vitamin_b12_ug_per_day_pri', 'values'],
        'pantothenic_acid_mg' => ['vitamins.pantothenic_acid_mg_per_day_ai', 'values'],
        'vitamin_d_ug' => ['vitamins.vitamin_d_ug_per_day_ai', 'values'],
        'vitamin_k_ug' => ['vitamins.vitamin_k_ug_per_day_ai', 'values'],
    ];

    public function __construct(private readonly EfsaReference $efsa)
    {
    }

    /**
     * Besoin d'un équipier (§2).
     *
     * @param array{id?: int, sexe: bool, age: int|float, poids_kg: float, pal: float} $eq
     *
     * @return array<string, mixed> BesoinNutritionnel (non arrondi)
     */
    public function besoinIndividuel(array $eq, ?float $lpiMg = null, ?string $statutMenopause = null): array
    {
        $sexe = $eq['sexe'] ? 'M' : 'F';
        $age = (float) $eq['age'];
        $lpi = $lpiMg ?? $this->efsa->lpiParDefaut();
        $hypotheses = [];

        if ('F' === $sexe && null === $statutMenopause) {
            $statutMenopause = $age >= Parametres::AGE_MENOPAUSE ? 'postmenopausal' : 'premenopausal';
            $hypotheses[] = sprintf('statut ménopausique déduit de l\'âge (seuil %d ans)', Parametres::AGE_MENOPAUSE);
        }
        if (null === $lpiMg) {
            $hypotheses[] = sprintf('apport en phytates supposé à %d mg/j (zinc)', $lpi);
        }

        // Énergie : AR (MJ) selon PAL. Au-delà de 79 ans l'EFSA ne donne rien : on garde la dernière tranche.
        $ligneEnergie = $this->efsa->ligne($this->efsa->bloc('energy_ar.values'), $age, $sexe, true);
        if ($age > Parametres::AGE_MAX_EFSA_ENERGIE) {
            $hypotheses[] = 'énergie : pas de valeur EFSA au-delà de 79 ans, tranche 70-79 ans utilisée';
        }
        $energieMj = $this->energieMj($ligneEnergie, $eq['pal'], $hypotheses);
        $energieKcal = $energieMj * $this->efsa->kcalParMj();

        $proteines = $this->efsa->ligne($this->efsa->bloc('protein_g_per_kg_bw.values'), $age, $sexe, true);
        $glucides = $this->efsa->ligne($this->efsa->bloc('macronutrient_energy_ranges.total_carbohydrates_e_percent.values'), $age, $sexe);
        $lipides = $this->efsa->ligne($this->efsa->bloc('macronutrient_energy_ranges.total_fat_e_percent.values'), $age, $sexe);

        $mineraux = [];
        foreach (self::MINERAUX as $cle => [$chemin, $liste]) {
            $ligne = $this->efsa->ligne($this->efsa->bloc($chemin.'.'.$liste) ?? [], $age, $sexe);
            $mineraux[$cle] = $this->efsa->valeur($ligne, $statutMenopause, $lpi);
        }

        $vitamines = [];
        foreach (self::VITAMINES as $cle => $source) {
            if (null === $source) {
                $bloc = 'niacin_mg' === $cle ? 'vitamins.niacin_mg_ne_per_mj' : 'vitamins.thiamin_mg_per_mj';
                $vitamines[$cle] = (float) $this->efsa->bloc($bloc.'.pri_all_ages') * $energieMj;
                continue;
            }
            [$chemin, $liste] = $source;
            $ligne = $this->efsa->ligne($this->efsa->bloc($chemin.'.'.$liste) ?? [], $age, $sexe);
            $vitamines[$cle] = $this->efsa->valeur($ligne);
        }

        $tranche = $ligneEnergie['age_label'] ?? null;

        return [
            'energy_kcal' => $energieKcal,
            'protein_g' => $proteines ? (float) $proteines['pri'] * $eq['poids_kg'] : null,
            'carbohydrates_g' => $glucides ? [
                'min' => $energieKcal * $glucides['min'] / 100 / 4,
                'max' => $energieKcal * $glucides['max'] / 100 / 4,
            ] : null,
            'lipids_g' => $lipides ? [
                'min' => $energieKcal * $lipides['min'] / 100 / 9,
                'max' => $energieKcal * $lipides['max'] / 100 / 9,
            ] : null,
            'fiber_g' => $this->efsa->valeur($this->efsa->ligne($this->efsa->bloc('dietary_fibre_ai_g_per_day.values'), $age, $sexe)),
            'water_l' => $this->efsa->valeur($this->efsa->ligne($this->efsa->bloc('water_ai_l_per_day.values'), $age, $sexe)),
            'minerals' => $mineraux,
            'vitamins' => $vitamines,
            'meta' => [
                'equipage_id' => $eq['id'] ?? null,
                'age_bracket' => $tranche,
                'sex_used' => $sexe,
                'pal_used' => $eq['pal'],
                'lpi_mg_assumption' => null === $lpiMg ? $lpi : null,
                'menopause_status_assumption' => 'F' === $sexe ? $statutMenopause : null,
                'hypotheses' => $hypotheses,
            ],
        ];
    }

    /**
     * Somme de plusieurs BesoinNutritionnel (§3.1). Les fourchettes sont sommées borne à borne.
     *
     * @param list<array<string, mixed>> $besoins
     *
     * @return array<string, mixed>
     */
    public function sommer(array $besoins): array
    {
        $total = null;
        foreach ($besoins as $b) {
            unset($b['meta']);
            $total = null === $total ? $b : self::combiner($total, $b, 1.0);
        }
        $total ??= self::combiner($this->besoinVide(), [], 1.0);
        $total['meta'] = ['nb_equipiers' => count($besoins)];

        return $total;
    }

    /**
     * Multiplie un besoin par un facteur (ex. nombre de jours). L'eau suit le même facteur.
     *
     * @param array<string, mixed> $besoin
     *
     * @return array<string, mixed>
     */
    public function multiplier(array $besoin, float $facteur): array
    {
        $meta = $besoin['meta'] ?? [];
        unset($besoin['meta']);
        $r = self::echelle($besoin, $facteur);
        $r['meta'] = $meta;

        return $r;
    }

    /**
     * Part d'un besoin journalier revenant à un créneau (§3.2). L'eau est répartie à parts
     * égales entre les créneaux (hydratation continue), le reste suit le coefficient du créneau.
     *
     * @param array<string, mixed> $besoin
     *
     * @return array<string, mixed>
     */
    public function pourCreneau(array $besoin, string $typeRepas): array
    {
        $coefficient = self::coefficientCreneau($typeRepas);
        $eau = $besoin['water_l'] ?? null;
        $r = $this->multiplier($besoin, $coefficient);
        $r['water_l'] = null !== $eau ? $eau / count(Parametres::COEFFICIENTS_CRENEAU) : null;
        $r['meta']['type_repas'] = $typeRepas;
        $r['meta']['coefficient'] = $coefficient;

        return $r;
    }

    public static function coefficientCreneau(string $typeRepas): float
    {
        $cle = Texte::cle($typeRepas);
        if (!isset(Parametres::COEFFICIENTS_CRENEAU[$cle])) {
            throw MoteurException::invalide(sprintf('type_repas inconnu : « %s »', $typeRepas));
        }

        return Parametres::COEFFICIENTS_CRENEAU[$cle];
    }

    /**
     * Besoins de tous les équipiers, indexés par id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function besoinsEquipage(DonneesVaisseau $d): array
    {
        $besoins = [];
        foreach ($d->equipages as $id => $eq) {
            $besoins[$id] = $this->besoinIndividuel($eq);
        }

        return $besoins;
    }

    /** GET /api/equipages/{id}/besoins */
    public function besoinEquipier(DonneesVaisseau $d, int $equipageId, ?float $lpiMg = null, ?string $statutMenopause = null): array
    {
        $eq = $d->equipages[$equipageId] ?? throw MoteurException::introuvable(sprintf('Équipage %d introuvable', $equipageId));
        if (null !== $statutMenopause && !in_array($statutMenopause, ['premenopausal', 'postmenopausal'], true)) {
            throw MoteurException::invalide('statut_menopause attendu : premenopausal ou postmenopausal');
        }

        return self::formater($this->besoinIndividuel($eq, $lpiMg, $statutMenopause));
    }

    /** GET /api/equipages/besoins?date= */
    public function besoinEquipe(DonneesVaisseau $d, \DateTimeImmutable $date): array
    {
        $besoins = $this->besoinsEquipage($d);
        $parEquipage = [];
        foreach ($besoins as $id => $b) {
            $parEquipage[] = ['equipage_id' => $id, 'besoin' => self::formater($b)];
        }

        return [
            'date' => $date->format('Y-m-d'),
            'besoin_equipe_total' => self::formater($this->sommer(array_values($besoins))),
            'par_equipage' => $parEquipage,
        ];
    }

    /** GET /api/equipages/besoins/creneau?date=&type_repas= */
    public function besoinCreneau(DonneesVaisseau $d, \DateTimeImmutable $date, string $typeRepas): array
    {
        $besoins = $this->besoinsEquipage($d);
        $groupes = [];
        foreach ($this->groupesAllergies($d) as $groupe) {
            $groupes[] = [
                'equipage_ids' => $groupe['equipage_ids'],
                'allergenes' => $groupe['allergenes'],
                'besoin_creneau' => self::formater($this->pourCreneau(
                    $this->sommer(array_map(static fn ($id) => $besoins[$id], $groupe['equipage_ids'])),
                    $typeRepas
                )),
            ];
        }

        return [
            'date' => $date->format('Y-m-d'),
            'type_repas' => $typeRepas,
            'besoin_creneau_equipe' => self::formater($this->pourCreneau($this->sommer(array_values($besoins)), $typeRepas)),
            'groupes' => $groupes,
        ];
    }

    /**
     * Regroupe les équipiers qui ont exactement les mêmes allergies (§3.3).
     *
     * @param list<int>|null $equipageIds restreint aux équipiers donnés
     *
     * @return list<array{equipage_ids: list<int>, allergene_ids: list<int>, allergenes: list<string>}>
     */
    public function groupesAllergies(DonneesVaisseau $d, ?array $equipageIds = null): array
    {
        $groupes = [];
        foreach ($d->equipages as $id => $eq) {
            if (null !== $equipageIds && !in_array($id, $equipageIds, true)) {
                continue;
            }
            $allergenes = $eq['allergenes'];
            sort($allergenes);
            $signature = implode(',', $allergenes);
            $groupes[$signature] ??= [
                'equipage_ids' => [],
                'allergene_ids' => $allergenes,
                'allergenes' => array_map(static fn ($a) => $d->allergenes[$a]['libelle'] ?? (string) $a, $allergenes),
            ];
            $groupes[$signature]['equipage_ids'][] = $id;
        }
        ksort($groupes);

        return array_values($groupes);
    }

    /**
     * Besoin en macronutriments d'un ensemble d'équipiers pour une journée.
     *
     * @param array<int, array<string, mixed>> $besoins
     * @param list<int>                        $equipageIds
     *
     * @return array<string, float>
     */
    public static function macrosEquipe(array $besoins, array $equipageIds): array
    {
        $total = Nutriments::zero();
        foreach ($equipageIds as $id) {
            $total = Nutriments::ajouter($total, Nutriments::depuisBesoin($besoins[$id]));
        }

        return $total;
    }

    /**
     * Arrondit un BesoinNutritionnel pour la sortie JSON.
     *
     * @param array<string, mixed> $besoin
     *
     * @return array<string, mixed>
     */
    public static function formater(array $besoin): array
    {
        $arrondir = static function ($v) use (&$arrondir) {
            if (is_array($v)) {
                return array_map($arrondir, $v);
            }

            return is_float($v) ? round($v, $v >= 100 ? 1 : 2) : $v;
        };
        $meta = $besoin['meta'] ?? [];
        unset($besoin['meta']);
        $r = $arrondir($besoin);
        $r['meta'] = $meta;

        return $r;
    }

    /** @param list<string> $hypotheses */
    private function energieMj(?array $ligne, float $pal, array &$hypotheses): float
    {
        if (null === $ligne) {
            return 0.0;
        }
        if (isset($ligne['ar_mj'])) {
            return (float) $ligne['ar_mj'];
        }
        $parPal = $ligne['ar_mj_by_pal'];
        foreach ($parPal as $p => $mj) {
            if (abs((float) $p - $pal) < 1e-6) {
                return (float) $mj;
            }
        }
        // AR = dépense de repos × PAL : on retrouve la dépense de repos depuis les paliers tabulés,
        // ce qui donne une valeur exacte pour un PAL hors table (ex. 1.2 ou 1.9).
        $repos = 0.0;
        foreach ($parPal as $p => $mj) {
            $repos += (float) $mj / (float) $p;
        }
        $hypotheses[] = sprintf('PAL %.1f absent des tables EFSA : énergie extrapolée (dépense de repos × PAL)', $pal);

        return $repos / count($parPal) * $pal;
    }

    /**
     * Combine deux arbres de besoins : $a + $b × $facteur, feuille à feuille (null reste null).
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    private static function combiner(array $a, array $b, float $facteur): array
    {
        $r = [];
        foreach (array_keys($a + $b) as $cle) {
            $va = $a[$cle] ?? null;
            $vb = $b[$cle] ?? null;
            if (is_array($va) || is_array($vb)) {
                $r[$cle] = self::combiner(is_array($va) ? $va : self::echelle($vb, 0.0), is_array($vb) ? $vb : [], $facteur);
            } elseif (null === $va && null === $vb) {
                $r[$cle] = null;
            } else {
                $r[$cle] = (float) ($va ?? 0.0) + (float) ($vb ?? 0.0) * $facteur;
            }
        }

        return $r;
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private static function echelle(array $a, float $facteur): array
    {
        return array_map(
            static fn ($v) => is_array($v) ? self::echelle($v, $facteur) : (null === $v ? null : (float) $v * $facteur),
            $a
        );
    }

    /** @return array<string, mixed> */
    private function besoinVide(): array
    {
        return [
            'energy_kcal' => 0.0, 'protein_g' => 0.0,
            'carbohydrates_g' => ['min' => 0.0, 'max' => 0.0], 'lipids_g' => ['min' => 0.0, 'max' => 0.0],
            'fiber_g' => 0.0, 'water_l' => 0.0,
            'minerals' => array_fill_keys(array_keys(self::MINERAUX), 0.0),
            'vitamins' => array_fill_keys(array_keys(self::VITAMINES), 0.0),
        ];
    }
}
