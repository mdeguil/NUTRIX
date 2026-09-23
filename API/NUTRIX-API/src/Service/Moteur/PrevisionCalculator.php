<?php

namespace App\Service\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\Support\Nutriments;
use App\Service\Moteur\Support\Parametres;

/**
 * Prévision alimentaire sur 8 semaines et plan de culture (MOTEUR_CALCUL.md §10 et §11).
 *
 * La prévision travaille aliment par aliment, au jour près :
 *
 *  1. Demande : les repas du PLANNING_REPAS là où il existe ; ailleurs un « menu moyen » par créneau
 *     (chaque recette servable à ce créneau est servie aussi souvent que les autres, ce que vise le
 *     roulement anti-lassitude), avec une part calée sur l'énergie du créneau.
 *  2. Simulation du stock (réserve courante) : récoltes attendues en entrée, consommation en FEFO,
 *     pertes des lots qui périment.
 *  3. Seuils de gestion de stock, pour chaque aliment :
 *       stock minimum  = conso/jour × JOURS_STOCK_MINIMUM
 *       stock d'alerte = stock minimum + conso/jour × délai d'obtention (cycle de culture moyen)
 *       stock maximum  = stock minimum + conso/jour × JOURS_COUVERTURE_CIBLE
 *     Le stock d'alerte se compare à la position de stock (stock + quantités en culture), comme un
 *     point de commande : sous ce seuil, il faut semer maintenant pour que la récolte arrive avant
 *     que le stock ne passe sous le minimum.
 *  4. Plan de semis (calcul des besoins nets, semaine par semaine) : pour un semis fait la semaine w,
 *     la récolte arrive à w + cycle. Si, sans ce semis, le stock passerait sous le minimum dans la
 *     semaine qui suit cette récolte, on sème de quoi remonter au stock maximum, dans la limite de ce
 *     qui peut être consommé avant péremption.
 */
class PrevisionCalculator
{
    public function __construct(
        private readonly BesoinsCalculator $besoins,
        private readonly RecetteCalculator $recettes,
        private readonly StockCalculator $stock,
    ) {
    }

    /** GET /api/stock/previsions?semaines=8 */
    public function previsions(DonneesVaisseau $d, int $semaines = Parametres::PREVISION_SEMAINES): array
    {
        return $this->analyser($d, $semaines);
    }

    /** GET /api/stock/besoins-agricoles?semaine= */
    public function besoinsAgricoles(DonneesVaisseau $d, ?int $semaine = null, int $semaines = Parametres::PREVISION_SEMAINES): array
    {
        if (null !== $semaine && ($semaine < 1 || $semaine > $semaines)) {
            throw MoteurException::invalide(sprintf('semaine doit être comprise entre 1 et %d', $semaines));
        }
        $a = $this->analyser($d, $semaines);
        $besoins = array_values(array_filter($a['plan_semis'], static fn ($s) => null === $semaine || $s['semaine'] === $semaine));

        $totaux = ['surface_m2' => 0.0, 'semences_necessaires' => 0.0, 'eau_l' => 0.0, 'energie_kwh' => 0.0];
        foreach ($besoins as $b) {
            foreach ($totaux as $cle => $v) {
                $totaux[$cle] = $v + $b[$cle];
            }
        }

        return [
            'semaine' => $semaine,
            'date_debut' => $a['date_debut'],
            'besoins' => $besoins,
            'totaux' => array_map(static fn ($v) => round($v, 2), $totaux),
            'hors_culture' => array_values(array_filter($a['alertes'], static fn ($al) => 'approvisionnement' === $al['type'])),
            'meta' => $a['meta'],
        ];
    }

    /**
     * Analyse complète, partagée par les prévisions et les besoins agricoles.
     *
     * @return array<string, mixed>
     */
    public function analyser(DonneesVaisseau $d, int $semaines = Parametres::PREVISION_SEMAINES): array
    {
        if ($semaines < 1 || $semaines > 52) {
            throw MoteurException::invalide('semaines doit être compris entre 1 et 52');
        }
        $t0 = $d->aujourdhui;
        $horizon = 7 * $semaines;

        $cycleMax = 0;
        foreach ($d->aliments as $al) {
            if (self::estCultivable($al)) {
                $cycleMax = max($cycleMax, self::cycleJours($al));
            }
        }
        // On simule au-delà de l'horizon : un semis de la semaine 8 est récolté bien après.
        $duree = $horizon + $cycleMax + 7;

        $besoinsEq = $this->besoins->besoinsEquipage($d);
        $besoinJour = BesoinsCalculator::macrosEquipe($besoinsEq, array_keys($besoinsEq));
        $demande = $this->demande($d, $besoinsEq, $horizon, $duree);

        $lotsInitiaux = [];
        foreach ($this->stock->lotsUtilisables($d, $t0) as $lot) {
            $lotsInitiaux[$lot['aliment']][] = ['q' => $lot['quantite_g'], 'exp' => self::jour($t0, $lot['date_peremption'])];
        }
        $reserves = $this->stock->parReserve($d, $t0);

        $recoltes = [];
        foreach ($d->recoltes as $r) {
            if (!in_array($r['statut'], Parametres::STATUTS_RECOLTE_A_VENIR, true)) {
                continue;
            }
            $jour = max(0, self::jour($t0, $r['date_recolte_prevue']));
            if ($jour < $duree) {
                $recoltes[$r['aliment']][] = ['jour' => $jour, 'q' => StockCalculator::quantiteNetteRecolte($d, $r), 'recolte_id' => $r['id']];
            }
        }

        $codes = array_unique(array_merge(array_keys($demande['par_aliment']), array_keys($lotsInitiaux), array_keys($recoltes)));
        sort($codes);

        $aliments = [];
        $planSemis = [];
        $alertes = [];
        $simulations = [];

        foreach ($codes as $code) {
            $aliment = $d->aliments[$code] ?? null;
            if (null === $aliment) {
                continue;
            }
            $conso = $demande['par_aliment'][$code] ?? array_fill(0, $duree, 0.0);
            $consoMoy = array_sum(array_slice($conso, 0, $horizon)) / $horizon;
            $conservation = self::dureeConservation($d, $code);
            $cultivable = self::estCultivable($aliment);
            $delai = $cultivable ? self::cycleJours($aliment) : Parametres::DELAI_APPRO_NON_CULTIVABLE_JOURS;
            $seuils = [
                'stock_minimum_g' => $consoMoy * Parametres::JOURS_STOCK_MINIMUM,
                'stock_alerte_g' => $consoMoy * (Parametres::JOURS_STOCK_MINIMUM + $delai),
                'stock_maximum_g' => $consoMoy * (Parametres::JOURS_STOCK_MINIMUM + Parametres::JOURS_COUVERTURE_CIBLE),
            ];
            if (null !== $conservation) {
                // Au-delà de ce qui peut être consommé avant péremption, le surplus serait perdu.
                $seuils['stock_maximum_g'] = max($seuils['stock_minimum_g'], min($seuils['stock_maximum_g'], $consoMoy * $conservation));
            }

            $arriveesBase = [];
            foreach ($recoltes[$code] ?? [] as $r) {
                $arriveesBase[] = ['jour' => $r['jour'], 'q' => $r['q'], 'semis' => 0, 'source' => 'recolte_'.$r['recolte_id']];
            }
            $lots = $lotsInitiaux[$code] ?? [];

            $sansPlan = $this->simuler($lots, $arriveesBase, $conso, $conservation, $duree);

            $semis = [];
            if ($cultivable && $consoMoy > 0) {
                $semis = $this->planifierSemis($aliment, $lots, $arriveesBase, $conso, $conservation, $duree, $semaines, $seuils, $consoMoy, $d);
            }
            $arrivees = array_merge($arriveesBase, array_map(static fn ($s) => [
                'jour' => $s['jour_recolte'], 'q' => $s['quantite_nette_g'], 'semis' => $s['jour_semis'], 'source' => 'plan',
            ], $semis));
            $avecPlan = $semis ? $this->simuler($lots, $arrivees, $conso, $conservation, $duree) : $sansPlan;
            $simulations[$code] = ['sans_plan' => $sansPlan, 'avec_plan' => $avecPlan];

            $parSemaine = [];
            for ($w = 0; $w < $semaines; ++$w) {
                $parSemaine[] = $this->semaine($avecPlan, $arrivees, $w, $seuils, $t0);
            }
            $positionActuelle = array_sum(array_column($lots, 'q')) + array_sum(array_column($arriveesBase, 'q'));

            $rupture = self::premierJour($sansPlan['manque'], $horizon);
            $ruptureAvecPlan = self::premierJour($avecPlan['manque'], $horizon);
            $sousMin = self::premierJourSous($sansPlan['stock'], $seuils['stock_minimum_g'], $horizon);

            $resume = [
                'aliment_id' => $code,
                'libelle' => $aliment['libelle'],
                'cultivable' => $cultivable,
                'delai_obtention_jours' => $delai,
                'duree_conservation_jours' => $conservation,
                'mode_estimation' => $demande['modes'][$code] ?? null,
                'consommation_moyenne_g_jour' => round($consoMoy, 1),
                'seuils' => array_map(static fn ($v) => round($v, 1), $seuils),
                'stock_actuel_g' => round(array_sum(array_column($lots, 'q')), 1),
                'reserves_g' => array_map(static fn ($v) => round($v, 1), $reserves[$code] ?? []),
                'en_culture_g' => round(array_sum(array_column($arriveesBase, 'q')), 1),
                'position_stock_g' => round($positionActuelle, 1),
                'statut' => self::niveau($lots ? array_sum(array_column($lots, 'q')) : 0.0, $positionActuelle, $seuils, $consoMoy > 0 && !$lots),
                'rupture_sans_plan' => null !== $rupture ? $t0->modify("+$rupture days")->format('Y-m-d') : null,
                'rupture_avec_plan' => null !== $ruptureAvecPlan ? $t0->modify("+$ruptureAvecPlan days")->format('Y-m-d') : null,
                'passage_sous_minimum' => null !== $sousMin ? $t0->modify("+$sousMin days")->format('Y-m-d') : null,
                'semaines' => $parSemaine,
                'semis_recommandes' => $semis,
            ];
            if ($consoMoy <= 0 && !$semis) {
                $resume['statut'] = 'sans_consommation_prevue';
            }
            $aliments[] = $resume;

            foreach ($semis as $s) {
                $planSemis[] = ['aliment_id' => $code, 'libelle' => $aliment['libelle']] + $s;
            }
            array_push($alertes, ...$this->alertesAliment($resume, $cultivable, $delai, $avecPlan, $horizon, $t0));
        }

        usort($planSemis, static fn ($a, $b) => [$a['semaine'], $a['aliment_id']] <=> [$b['semaine'], $b['aliment_id']]);
        foreach ($planSemis as &$s) {
            unset($s['jour_semis'], $s['jour_recolte']);
        }
        unset($s);
        foreach ($aliments as &$al) {
            foreach ($al['semis_recommandes'] as &$s) {
                unset($s['jour_semis'], $s['jour_recolte']);
            }
            unset($s);
        }
        unset($al);

        $vueNutriments = $this->vueNutriments($d, $simulations, $besoinJour, $semaines, $t0);

        return [
            'date_debut' => $t0->format('Y-m-d'),
            'nb_semaines' => $semaines,
            'nb_equipiers' => count($besoinsEq),
            'semaines' => $vueNutriments,
            'aliments' => $aliments,
            'plan_semis' => $planSemis,
            'synthese_semis' => self::synthese($planSemis, $semaines, $t0),
            'alertes' => $alertes,
            'meta' => [
                'reserve_simulee' => Parametres::RESERVE_UTILISABLE,
                'jours_stock_minimum' => Parametres::JOURS_STOCK_MINIMUM,
                'jours_couverture_cible' => Parametres::JOURS_COUVERTURE_CIBLE,
                'hypotheses' => [
                    'demande hors planning : menu moyen par créneau, chaque recette servable étant servie aussi souvent',
                    sprintf('part = énergie du créneau, bornée entre %d et %d g', Parametres::PORTION_G_MIN, Parametres::PORTION_G_MAX),
                    'besoins glucides/lipides : borne basse de la fourchette EFSA',
                    sprintf('coefficients agricoles par défaut : %s graines/m², %s L/m²/j, %s kWh/m²/j',
                        Parametres::DENSITE_SEMIS_GRAINES_M2, Parametres::CONSO_EAU_L_M2_J, Parametres::CONSO_ENERGIE_KWH_M2_J),
                ],
            ],
        ];
    }

    /**
     * Demande journalière par aliment (g), sur `$duree` jours.
     *
     * @param array<int, array<string, mixed>> $besoinsEq
     *
     * @return array{par_aliment: array<string, list<float>>, modes: array<string, string>}
     */
    public function demande(DonneesVaisseau $d, array $besoinsEq, int $horizon, int $duree): array
    {
        $nb = count($besoinsEq);
        $energieMoyenne = $nb > 0 ? array_sum(array_map(static fn ($b) => $b['energy_kcal'], $besoinsEq)) / $nb : 0.0;
        $parAliment = [];
        $modes = [];
        // Mode d'estimation le moins fiable parmi les recettes qui consomment l'aliment.
        $fiabilite = [RecetteCalculator::MODE_NOMINAL => 0, RecetteCalculator::MODE_EMPIRIQUE => 1, RecetteCalculator::MODE_UNIFORME => 2];
        $ajouter = function (int $jour, array $recette, float $grammes) use ($d, &$parAliment, &$modes, $duree, $fiabilite) {
            $composition = $this->recettes->composition($d, $recette);
            foreach ($composition['aliments'] as $code => $g100) {
                $parAliment[$code] ??= array_fill(0, $duree, 0.0);
                $parAliment[$code][$jour] += $grammes * $g100 / 100;
                if ($fiabilite[$composition['mode']] > $fiabilite[$modes[$code] ?? RecetteCalculator::MODE_NOMINAL] || !isset($modes[$code])) {
                    $modes[$code] = $composition['mode'];
                }
            }
        };

        // Menu moyen par créneau : une part par personne, recette tirée uniformément parmi celles servables.
        $menuMoyen = [];
        foreach (array_keys(Parametres::COEFFICIENTS_CRENEAU) as $creneau) {
            $candidates = array_filter($d->recettes, fn ($r) => in_array($creneau, $this->recettes->creneaux($d, $r), true) && $r['pour100g']['kcal'] > 0);
            foreach ($candidates as $r) {
                $menuMoyen[$creneau][] = [$r, $nb * RecetteCalculator::portionG($r, $creneau, $energieMoyenne) / count($candidates)];
            }
        }

        $planifie = [];
        foreach ($d->planning as $p) {
            $jour = self::jour($d->aujourdhui, $p['date']);
            if ($jour < 0 || $jour >= $horizon || !isset($d->recettes[$p['recette_id']])) {
                continue;
            }
            $planifie[$jour][$p['type_repas']] = true;
            $recette = $d->recettes[$p['recette_id']];
            $ajouter($jour, $recette, $p['portions'] * RecetteCalculator::portionG($recette, $p['type_repas'], $energieMoyenne));
        }

        for ($jour = 0; $jour < $duree; ++$jour) {
            foreach ($menuMoyen as $creneau => $menu) {
                if (isset($planifie[$jour][$creneau])) {
                    continue;
                }
                foreach ($menu as [$recette, $grammes]) {
                    $ajouter($jour, $recette, $grammes);
                }
            }
        }

        return ['par_aliment' => $parAliment, 'modes' => $modes];
    }

    /**
     * Simulation jour par jour du stock d'un aliment.
     *
     * @param list<array{q: float, exp: int|null}>                   $lots
     * @param list<array{jour: int, q: float, semis: int, source: string}> $arrivees
     * @param list<float>                                            $conso
     *
     * @return array{stock: list<float>, servi: list<float>, manque: list<float>, pertes: list<float>, arrive: list<float>}
     */
    public function simuler(array $lots, array $arrivees, array $conso, ?int $conservation, int $duree): array
    {
        $parJour = [];
        foreach ($arrivees as $a) {
            $parJour[$a['jour']][] = ['q' => $a['q'], 'exp' => null !== $conservation ? $a['jour'] + $conservation : null];
        }
        $r = ['stock' => [], 'servi' => [], 'manque' => [], 'pertes' => [], 'arrive' => []];
        $fefo = static fn ($a, $b) => [null === $a['exp'], $a['exp']] <=> [null === $b['exp'], $b['exp']];
        usort($lots, $fefo);

        for ($t = 0; $t < $duree; ++$t) {
            $arrive = 0.0;
            if (isset($parJour[$t])) {
                foreach ($parJour[$t] as $l) {
                    $lots[] = $l;
                    $arrive += $l['q'];
                }
                usort($lots, $fefo);
            }
            $pertes = 0.0;
            foreach ($lots as $i => $l) {
                if (null !== $l['exp'] && $l['exp'] < $t) {
                    $pertes += $l['q'];
                    unset($lots[$i]);
                }
            }
            $lots = array_values($lots);

            $besoin = $conso[$t] ?? 0.0;
            $servi = 0.0;
            foreach ($lots as $i => $l) {
                if ($besoin - $servi <= 1e-9) {
                    break;
                }
                $pris = min($l['q'], $besoin - $servi);
                $lots[$i]['q'] -= $pris;
                $servi += $pris;
            }
            $lots = array_values(array_filter($lots, static fn ($l) => $l['q'] > 1e-9));

            $r['stock'][] = (float) array_sum(array_column($lots, 'q'));
            $r['servi'][] = $servi;
            $r['manque'][] = max(0.0, $besoin - $servi);
            $r['pertes'][] = $pertes;
            $r['arrive'][] = $arrive;
        }

        return $r;
    }

    /**
     * Semis à faire chaque semaine pour un aliment cultivable (calcul des besoins nets).
     *
     * @return list<array<string, mixed>>
     */
    private function planifierSemis(array $aliment, array $lots, array $arriveesBase, array $conso, ?int $conservation, int $duree, int $semaines, array $seuils, float $consoMoy, DonneesVaisseau $d): array
    {
        $cycle = self::cycleJours($aliment);
        $tauxPerte = StockCalculator::tauxPerteHistorique($d, $aliment['id']);
        $plan = [];

        for ($w = 0; $w < $semaines; ++$w) {
            $jourSemis = 7 * $w;
            $jourRecolte = $jourSemis + $cycle;
            if ($jourRecolte >= $duree) {
                break;
            }
            $arrivees = array_merge($arriveesBase, array_map(static fn ($s) => [
                'jour' => $s['jour_recolte'], 'q' => $s['quantite_nette_g'], 'semis' => $s['jour_semis'], 'source' => 'plan',
            ], $plan));
            $sim = $this->simuler($lots, $arrivees, $conso, $conservation, $duree);

            // Fenêtre couverte par ce semis : de sa récolte jusqu'à la récolte du semis suivant.
            $fin = min($duree, $jourRecolte + 7);
            $stockMin = min(array_slice($sim['stock'], $jourRecolte, $fin - $jourRecolte));
            $manque = array_sum(array_slice($sim['manque'], $jourRecolte, $fin - $jourRecolte));
            if ($stockMin >= $seuils['stock_minimum_g'] && $manque <= 0) {
                continue;
            }

            $quantiteNette = $seuils['stock_maximum_g'] - $sim['stock'][$jourRecolte] + $sim['manque'][$jourRecolte];
            $raison = $manque > 0 ? 'rupture' : 'passage sous le stock minimum';
            if (null !== $conservation) {
                // Au-delà, la récolte périmerait avant d'être consommée.
                $plafond = $consoMoy * $conservation;
                if ($quantiteNette > $plafond) {
                    $quantiteNette = $plafond;
                    $raison .= ' (quantité limitée par la durée de conservation)';
                }
            }
            if ($quantiteNette <= 0) {
                continue;
            }

            $quantiteBrute = $quantiteNette / max(0.01, 1 - $tauxPerte);
            $surface = $quantiteBrute / ($aliment['rendement_g_m2_j'] * $cycle);
            $dateSemis = $d->aujourdhui->modify("+$jourSemis days");
            $plan[] = [
                'semaine' => $w + 1,
                'date_semis' => $dateSemis->format('Y-m-d'),
                'date_recolte_prevue' => $d->aujourdhui->modify("+$jourRecolte days")->format('Y-m-d'),
                'cycle_jours' => $cycle,
                'quantite_a_produire_g' => round($quantiteBrute, 1),
                'quantite_nette_g' => round($quantiteNette, 1),
                'taux_perte_retenu' => round($tauxPerte, 3),
                'surface_m2' => round($surface, 2),
                'semences_necessaires' => (int) ceil($surface * Parametres::DENSITE_SEMIS_GRAINES_M2),
                'eau_l' => round($surface * $cycle * Parametres::CONSO_EAU_L_M2_J, 1),
                'energie_kwh' => round($surface * $cycle * Parametres::CONSO_ENERGIE_KWH_M2_J, 1),
                'raison' => $raison,
                'jour_semis' => $jourSemis,
                'jour_recolte' => $jourRecolte,
            ];
        }

        return $plan;
    }

    /** Bilan d'une semaine pour un aliment. */
    private function semaine(array $sim, array $arrivees, int $w, array $seuils, \DateTimeImmutable $t0): array
    {
        $debut = 7 * $w;
        $fin = $debut + 6;
        $somme = static fn (string $cle) => round(array_sum(array_slice($sim[$cle], $debut, 7)), 1);
        $stockFin = $sim['stock'][$fin];
        $enCulture = 0.0;
        foreach ($arrivees as $a) {
            if ($a['jour'] > $fin && $a['semis'] <= $fin) {
                $enCulture += $a['q'];
            }
        }
        $manque = $somme('manque');

        return [
            'semaine' => $w + 1,
            'debut' => $t0->modify("+$debut days")->format('Y-m-d'),
            'stock_debut_g' => round(0 === $debut ? $sim['stock'][0] - $sim['arrive'][0] + $sim['servi'][0] + $sim['pertes'][0] : $sim['stock'][$debut - 1], 1),
            'recoltes_g' => $somme('arrive'),
            'consommation_g' => $somme('servi'),
            'manque_g' => $manque,
            'pertes_peremption_g' => $somme('pertes'),
            'stock_fin_g' => round($stockFin, 1),
            'en_culture_g' => round($enCulture, 1),
            'niveau' => $manque > 0 ? 'rupture' : self::niveau($stockFin, $stockFin + $enCulture, $seuils, false),
        ];
    }

    private static function niveau(float $stock, float $position, array $seuils, bool $vide): string
    {
        if ($vide) {
            return 'rupture';
        }
        if ($seuils['stock_minimum_g'] > 0 && $stock < $seuils['stock_minimum_g']) {
            return 'sous_minimum';
        }
        if ($seuils['stock_alerte_g'] > 0 && $position < $seuils['stock_alerte_g']) {
            return 'alerte';
        }
        if ($seuils['stock_maximum_g'] > 0 && $stock > 2 * $seuils['stock_maximum_g']) {
            return 'surstock';
        }

        return 'ok';
    }

    /**
     * Vue par nutriment (format E2 d'API_BESOINS_PLANNING.md), sans puis avec le plan de semis.
     *
     * @param array<string, array{sans_plan: array, avec_plan: array}> $simulations
     * @param array<string, float>                                     $besoinJour
     *
     * @return list<array<string, mixed>>
     */
    private function vueNutriments(DonneesVaisseau $d, array $simulations, array $besoinJour, int $semaines, \DateTimeImmutable $t0): array
    {
        $vue = [];
        $seuilSecurite = Nutriments::multiplier($besoinJour, Parametres::JOURS_STOCK_MINIMUM);
        for ($w = 0; $w < $semaines; ++$w) {
            $debut = 7 * $w;
            $cumul = ['besoin' => [], 'debut' => [], 'recolte' => [], 'conso' => [], 'demande' => [], 'pertes' => [], 'stock' => [], 'stock_plan' => []];
            foreach ($simulations as $code => $s) {
                $sp = $s['sans_plan'];
                $grammes = static fn (array $sim, string $cle) => array_sum(array_slice($sim[$cle], $debut, 7));
                $cumul['debut'][$code] = 0 === $debut ? $sp['stock'][0] - $sp['arrive'][0] + $sp['servi'][0] + $sp['pertes'][0] : $sp['stock'][$debut - 1];
                $cumul['recolte'][$code] = $grammes($sp, 'arrive');
                $cumul['conso'][$code] = $grammes($sp, 'servi');
                $cumul['demande'][$code] = $grammes($sp, 'servi') + $grammes($sp, 'manque');
                $cumul['pertes'][$code] = $grammes($sp, 'pertes');
                $cumul['stock'][$code] = $sp['stock'][$debut + 6];
                $cumul['stock_plan'][$code] = $s['avec_plan']['stock'][$debut + 6];
            }
            $valeur = static fn (string $cle) => StockCalculator::valeurNutritionnelle($d, $cumul[$cle]);

            $besoin = Nutriments::multiplier($besoinJour, 7);
            $disponible = Nutriments::ajouter(Nutriments::ajouter($valeur('debut'), $valeur('recolte')), $valeur('pertes'), -1);
            $stock = $valeur('stock');
            $stockPlan = $valeur('stock_plan');
            $deficit = [];
            $aRisque = [];
            $aRisquePlan = [];
            foreach (Nutriments::CLES as $cle) {
                $deficit[$cle] = max(0.0, $besoin[$cle] - $disponible[$cle]);
                if ($deficit[$cle] > 0 || $stock[$cle] < $seuilSecurite[$cle]) {
                    $aRisque[] = $cle;
                }
                if ($stockPlan[$cle] < $seuilSecurite[$cle]) {
                    $aRisquePlan[] = $cle;
                }
            }

            $vue[] = [
                'semaine' => $w + 1,
                'debut' => $t0->modify("+$debut days")->format('Y-m-d'),
                'fin' => $t0->modify('+'.($debut + 6).' days')->format('Y-m-d'),
                'besoin' => Nutriments::arrondir($besoin),
                'stock_debut' => Nutriments::arrondir($valeur('debut')),
                'recolte_prevue' => Nutriments::arrondir($valeur('recolte')),
                'consommation_planifiee' => Nutriments::arrondir($valeur('demande')),
                'consommation_servie' => Nutriments::arrondir($valeur('conso')),
                'pertes_peremption' => Nutriments::arrondir($valeur('pertes')),
                'stock_projete' => Nutriments::arrondir($stock),
                'stock_projete_avec_plan' => Nutriments::arrondir($stockPlan),
                'seuil_securite' => Nutriments::arrondir($seuilSecurite),
                'deficit' => Nutriments::arrondir($deficit),
                'periode_a_risque' => [] !== $aRisque,
                'nutriments_a_risque' => $aRisque,
                'periode_a_risque_avec_plan' => [] !== $aRisquePlan,
            ];
        }

        return $vue;
    }

    /** @return list<array<string, mixed>> */
    private function alertesAliment(array $resume, bool $cultivable, int $delai, array $avecPlan, int $horizon, \DateTimeImmutable $t0): array
    {
        $alertes = [];
        $code = $resume['aliment_id'];
        if ($resume['consommation_moyenne_g_jour'] <= 0) {
            return [];
        }

        if (!$cultivable && null !== $resume['rupture_sans_plan']) {
            $alertes[] = [
                'niveau' => 'critique', 'type' => 'approvisionnement', 'aliment_id' => $code,
                'date' => $resume['rupture_sans_plan'],
                'message' => sprintf('%s : rupture le %s, aliment non cultivable (à transformer ou à puiser dans les réserves)', $resume['libelle'], $resume['rupture_sans_plan']),
            ];
        } elseif (!$cultivable && in_array($resume['statut'], ['alerte', 'sous_minimum'], true)) {
            $alertes[] = [
                'niveau' => 'warning', 'type' => 'approvisionnement', 'aliment_id' => $code, 'date' => $t0->format('Y-m-d'),
                'message' => sprintf('%s : stock sous le seuil d\'alerte, aliment non cultivable', $resume['libelle']),
            ];
        }

        if ($cultivable) {
            // Rupture que la culture ne peut pas empêcher : elle tombe avant la première récolte possible.
            $manqueInevitable = array_sum(array_slice($avecPlan['manque'], 0, min($delai, $horizon)));
            if ($manqueInevitable > 0) {
                $reserve = array_sum($resume['reserves_g']) - ($resume['reserves_g']['courante'] ?? 0);
                $alertes[] = [
                    'niveau' => 'critique', 'type' => 'rupture_avant_recolte', 'aliment_id' => $code,
                    'date' => $resume['rupture_avec_plan'] ?? $resume['rupture_sans_plan'],
                    'manque_g' => round($manqueInevitable, 1),
                    'reserve_mobilisable_g' => round($reserve, 1),
                    'message' => sprintf('%s : %.0f g manqueront avant la première récolte possible (cycle %d j). Réserves hors courante : %.0f g',
                        $resume['libelle'], $manqueInevitable, $delai, $reserve),
                ];
            }
            if ([] !== $resume['semis_recommandes']) {
                $premier = $resume['semis_recommandes'][0];
                $alertes[] = [
                    'niveau' => 1 === $premier['semaine'] ? 'warning' : 'info', 'type' => 'semis', 'aliment_id' => $code,
                    'date' => $premier['date_semis'],
                    'message' => sprintf('%s : semer %.0f g (%.2f m²) le %s pour récolte le %s', $resume['libelle'],
                        $premier['quantite_a_produire_g'], $premier['surface_m2'], $premier['date_semis'], $premier['date_recolte_prevue']),
                ];
            }
        }

        return $alertes;
    }

    /** @return list<array<string, mixed>> */
    private static function synthese(array $planSemis, int $semaines, \DateTimeImmutable $t0): array
    {
        $synthese = [];
        for ($w = 1; $w <= $semaines; ++$w) {
            $lignes = array_filter($planSemis, static fn ($s) => $s['semaine'] === $w);
            $synthese[] = [
                'semaine' => $w,
                'debut' => $t0->modify('+'.(7 * ($w - 1)).' days')->format('Y-m-d'),
                'nb_semis' => count($lignes),
                'aliments' => array_values(array_column($lignes, 'aliment_id')),
                'surface_m2' => round(array_sum(array_column($lignes, 'surface_m2')), 2),
                'eau_l' => round(array_sum(array_column($lignes, 'eau_l')), 1),
                'energie_kwh' => round(array_sum(array_column($lignes, 'energie_kwh')), 1),
            ];
        }

        return $synthese;
    }

    public static function estCultivable(array $aliment): bool
    {
        return ($aliment['rendement_g_m2_j'] ?? 0) > 0 && $aliment['cycle_max'] > 0;
    }

    public static function cycleJours(array $aliment): int
    {
        return max(1, (int) round(($aliment['cycle_min'] + $aliment['cycle_max']) / 2));
    }

    /** Durée de conservation estimée d'un aliment : médiane (péremption − entrée) de ses lots. Null si inconnue. */
    public static function dureeConservation(DonneesVaisseau $d, string $code): ?int
    {
        $durees = [];
        foreach ($d->lots as $lot) {
            if ($lot['aliment'] === $code && null !== $lot['date_peremption']) {
                $durees[] = (int) $lot['date_entree']->diff($lot['date_peremption'])->format('%r%a');
            }
        }
        if (!$durees) {
            return null;
        }
        sort($durees);

        return max(1, $durees[intdiv(count($durees), 2)]);
    }

    /** Nombre de jours entre t0 et une date (négatif si passée), null si pas de date. */
    private static function jour(\DateTimeImmutable $t0, ?\DateTimeImmutable $date): ?int
    {
        return null === $date ? null : (int) $t0->diff($date->setTime(0, 0))->format('%r%a');
    }

    private static function premierJour(array $valeurs, int $limite): ?int
    {
        for ($t = 0; $t < $limite; ++$t) {
            if (($valeurs[$t] ?? 0) > 1e-6) {
                return $t;
            }
        }

        return null;
    }

    private static function premierJourSous(array $stock, float $seuil, int $limite): ?int
    {
        for ($t = 0; $t < $limite; ++$t) {
            if (($stock[$t] ?? 0) < $seuil) {
                return $t;
            }
        }

        return null;
    }
}
