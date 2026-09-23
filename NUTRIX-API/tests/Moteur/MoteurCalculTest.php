<?php

namespace App\Tests\Moteur;

use App\Service\Moteur\BesoinsCalculator;
use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\EcartCalculator;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\PlanningCalculator;
use App\Service\Moteur\PrevisionCalculator;
use App\Service\Moteur\RecetteCalculator;
use App\Service\Moteur\StockCalculator;
use App\Service\Moteur\Support\EfsaReference;
use App\Service\Moteur\Support\Parametres;
use PHPUnit\Framework\TestCase;

/**
 * Tests du moteur de calcul sur les données réelles du dump `Docs/nutrix (1).sql`, au 2026-09-23.
 * Aucune base n'est nécessaire.
 */
class MoteurCalculTest extends TestCase
{
    private BesoinsCalculator $besoins;
    private RecetteCalculator $recettes;
    private StockCalculator $stock;
    private PrevisionCalculator $previsions;
    private EcartCalculator $ecarts;
    private PlanningCalculator $planning;
    private DonneesVaisseau $d;

    protected function setUp(): void
    {
        if (!is_file(DonneesDump::chemin())) {
            self::markTestSkipped('Dump Docs/nutrix (1).sql absent');
        }
        $this->besoins = new BesoinsCalculator(new EfsaReference(dirname(__DIR__, 2).'/config/nutrix/efsa_drv_reference.json'));
        $this->recettes = new RecetteCalculator();
        $this->stock = new StockCalculator($this->besoins);
        $this->previsions = new PrevisionCalculator($this->besoins, $this->recettes, $this->stock);
        $this->ecarts = new EcartCalculator($this->besoins, $this->recettes);
        $this->planning = new PlanningCalculator($this->besoins, $this->recettes, $this->stock, $this->ecarts, $this->previsions);
        $this->d = DonneesDump::charger('2026-09-23');
    }

    public function testBesoinHommeActif(): void
    {
        // Équipage 1 : homme, 34 ans, 78,5 kg, PAL 1,6.
        $b = $this->besoins->besoinEquipier($this->d, 1);

        self::assertEqualsWithDelta(10.8 * 238.83, $b['energy_kcal'], 0.1);
        self::assertEqualsWithDelta(0.83 * 78.5, $b['protein_g'], 0.01);
        self::assertEqualsWithDelta($b['energy_kcal'] * 0.20 / 9, $b['lipids_g']['min'], 0.1);
        self::assertEqualsWithDelta($b['energy_kcal'] * 0.60 / 4, $b['carbohydrates_g']['max'], 0.1);
        self::assertSame(25.0, $b['fiber_g']);
        self::assertSame(2.5, $b['water_l']);
        self::assertSame(11.0, $b['minerals']['iron_mg']);
        self::assertSame(11.7, $b['minerals']['zinc_mg']);
        self::assertEqualsWithDelta(1.6 * 10.8, $b['vitamins']['niacin_mg'], 0.01);
        self::assertSame('30-39 y', $b['meta']['age_bracket']);
        self::assertSame(600.0, $b['meta']['lpi_mg_assumption']);
    }

    public function testPalHorsTableEstExtrapole(): void
    {
        // Équipage 3 : PAL 1,2, absent des tables EFSA (1,4 à 2,0).
        $b = $this->besoins->besoinEquipier($this->d, 3);
        $repos = (9.3 / 1.4 + 10.7 / 1.6 + 12.0 / 1.8 + 13.4 / 2.0) / 4;

        self::assertEqualsWithDelta($repos * 1.2 * 238.83, $b['energy_kcal'], 0.2);
        self::assertStringContainsString('PAL 1.2', implode(' ', $b['meta']['hypotheses']));
    }

    public function testFerSelonStatutMenopausique(): void
    {
        self::assertSame(11.0, $this->besoins->besoinEquipier($this->d, 13)['minerals']['iron_mg']); // femme, 55 ans
        self::assertSame(16.0, $this->besoins->besoinEquipier($this->d, 2)['minerals']['iron_mg']);  // femme, 29 ans
        self::assertSame(11.0, $this->besoins->besoinEquipier($this->d, 2, null, 'postmenopausal')['minerals']['iron_mg']);
    }

    public function testZincInterpoleSelonLpi(): void
    {
        self::assertEqualsWithDelta((11.7 + 14.0) / 2, $this->besoins->besoinEquipier($this->d, 1, 750.0)['minerals']['zinc_mg'], 0.01);
    }

    public function testBesoinEquipeEtCreneau(): void
    {
        $equipe = $this->besoins->besoinEquipe($this->d, $this->d->aujourdhui);
        $somme = array_sum(array_map(static fn ($e) => $e['besoin']['energy_kcal'], $equipe['par_equipage']));
        self::assertCount(19, $equipe['par_equipage']);
        self::assertEqualsWithDelta($somme, $equipe['besoin_equipe_total']['energy_kcal'], 1.0);

        $creneau = $this->besoins->besoinCreneau($this->d, $this->d->aujourdhui, 'Dejeuner');
        self::assertEqualsWithDelta($equipe['besoin_equipe_total']['energy_kcal'] * 0.35, $creneau['besoin_creneau_equipe']['energy_kcal'], 0.5);
        self::assertEqualsWithDelta($equipe['besoin_equipe_total']['water_l'] / 4, $creneau['besoin_creneau_equipe']['water_l'], 0.01);
        // 5 équipiers ont une allergie, chacun dans un groupe distinct sauf 4 et 8 (arachide).
        self::assertCount(5, $creneau['groupes']);
        self::assertContains([4, 8], array_column($creneau['groupes'], 'equipage_ids'));
    }

    public function testEquipageInconnu(): void
    {
        $this->expectExceptionObject(MoteurException::introuvable('Équipage 999 introuvable'));
        $this->besoins->besoinEquipier($this->d, 999);
    }

    public function testProfilRecette(): void
    {
        $bowl = $this->recettes->profilNutritionnel($this->d, 9);
        self::assertTrue($bowl['recompute_disponible']);
        // 50 g quinoa cuit + 30 g épinard + 15 g tomate + 5 g graines de tournesol.
        self::assertEqualsWithDelta(0.5 * 120 + 0.3 * 23 + 0.15 * 18 + 0.05 * 584, $bowl['valeur_recalculee_ingredients']['kcal'], 0.1);

        $legacy = $this->recettes->profilNutritionnel($this->d, 1);
        self::assertFalse($legacy['recompute_disponible']);
        self::assertArrayHasKey('warning', $legacy);
    }

    public function testAllergenesDeduitsDuCodeAliment(): void
    {
        self::assertSame([2], $this->recettes->allergenesAliment($this->d, 'LAIT_POUDRE'));
        self::assertSame([5], $this->recettes->allergenesAliment($this->d, 'LAIT_DE_SOJA'));
        self::assertSame([1], $this->recettes->allergenesAliment($this->d, 'FARINE_DE_BLE_COMPLET'));
        self::assertSame([3], $this->recettes->allergenesAliment($this->d, 'ARACHIDES_GRILLEES'));
        self::assertSame([4], $this->recettes->allergenesAliment($this->d, 'NOIX'));
        self::assertSame([], $this->recettes->allergenesAliment($this->d, 'NOIX_DE_TOURNESOL'));
    }

    public function testCompositionSelonDonneesDisponibles(): void
    {
        self::assertSame(RecetteCalculator::MODE_NOMINAL, $this->recettes->composition($this->d, $this->d->recettes[9])['mode']);
        // Salade de tomates : quantités à 0, mais une sortie de stock de tomates liée à la recette (Asso_11).
        self::assertSame(['mode' => RecetteCalculator::MODE_EMPIRIQUE, 'aliments' => ['TOMATE' => 100.0]], $this->recettes->composition($this->d, $this->d->recettes[4]));
        // Riz aux haricots : aucune sortie liée, répartition uniforme.
        self::assertEquals(['RIZ' => 50.0, 'HARICOT_ROUGE' => 50.0], $this->recettes->composition($this->d, $this->d->recettes[1])['aliments']);
    }

    public function testStockUtilisableEtAutonomie(): void
    {
        // Tomates périmées, haricots en réserve de sécurité, oeufs en urgence : seul le lait en poudre est utilisable.
        self::assertSame(['LAIT_POUDRE' => 12000.0], $this->stock->disponible($this->d, $this->d->aujourdhui));

        $a = $this->stock->autonomie($this->d);
        self::assertEqualsWithDelta(120 * 496 / $a['besoin_journalier_equipe']['kcal'], $a['par_nutriment']['kcal'], 0.05);
        self::assertSame('fibres_g', $a['nutriment_limitant']);
        self::assertSame(['RIZ' => 7600.0, 'SALADE' => 4275.0], $a['avec_recoltes_prevues']['recoltes_attendues_g']);
        self::assertSame('lot_perime', $a['alertes'][0]['type']);
    }

    public function testSortieFefo(): void
    {
        $d = clone $this->d;
        $d->lots = [
            self::lot(10, 'RIZ', 500, '2026-12-01'),
            self::lot(11, 'RIZ', 300, '2026-10-01'),
            self::lot(12, 'RIZ', 1000, null),
            self::lot(13, 'RIZ', 999, '2026-09-01'),
        ];
        $sortie = $this->stock->planifierSortie($d, 'RIZ', 1000, $d->aujourdhui);

        self::assertSame([[11, 300.0], [10, 500.0], [12, 200.0]], array_map(static fn ($s) => [$s['lot_id'], $s['quantite_g']], $sortie['sorties']));
        self::assertSame(0.0, $sortie['manque_g']);
    }

    public function testSimulationStockPertesEtRupture(): void
    {
        // 1 000 g qui périment au jour 2, 100 g consommés par jour, 500 g récoltés au jour 5.
        $sim = $this->previsions->simuler([['q' => 1000.0, 'exp' => 2]], [['jour' => 5, 'q' => 500.0, 'semis' => 0, 'source' => 't']], array_fill(0, 10, 100.0), null, 10);

        self::assertSame([900.0, 800.0, 700.0, 0.0, 0.0, 400.0, 300.0, 200.0, 100.0, 0.0], $sim['stock']);
        self::assertSame(700.0, $sim['pertes'][3]);
        self::assertSame(100.0, $sim['manque'][3]);
    }

    public function testPrevisionEtPlanDeSemis(): void
    {
        $p = $this->previsions->previsions($this->d, 8);
        self::assertCount(8, $p['semaines']);
        self::assertTrue($p['semaines'][0]['periode_a_risque']);

        $radis = self::aliment($p, 'RADIS');
        self::assertTrue($radis['cultivable']);
        self::assertSame(30, $radis['delai_obtention_jours']);
        $conso = $radis['consommation_moyenne_g_jour'];
        self::assertEqualsWithDelta($conso * 7, $radis['seuils']['stock_minimum_g'], 1);
        self::assertEqualsWithDelta($conso * (7 + 30), $radis['seuils']['stock_alerte_g'], 1);
        self::assertEqualsWithDelta($conso * (7 + 14), $radis['seuils']['stock_maximum_g'], 1);

        $semis = $radis['semis_recommandes'][0];
        self::assertSame(1, $semis['semaine']);
        self::assertSame('2026-10-23', $semis['date_recolte_prevue']);
        self::assertEqualsWithDelta($semis['quantite_a_produire_g'] / (2.0 * 30), $semis['surface_m2'], 0.01);

        // Une fois la première récolte arrivée, le stock reste entre le minimum et le maximum.
        foreach (array_slice($radis['semaines'], 5) as $semaine) {
            self::assertGreaterThanOrEqual($radis['seuils']['stock_minimum_g'], $semaine['stock_fin_g']);
            self::assertLessThanOrEqual($radis['seuils']['stock_maximum_g'], $semaine['stock_fin_g']);
            self::assertSame(0.0, $semaine['manque_g']);
        }
    }

    public function testQuantiteSemeeLimiteeParConservation(): void
    {
        // Les tomates se conservent 18 jours (lot 1) : on ne sème pas plus que 18 jours de consommation.
        $tomate = self::aliment($this->previsions->previsions($this->d, 8), 'TOMATE');
        self::assertSame(18, $tomate['duree_conservation_jours']);
        foreach ($tomate['semis_recommandes'] as $s) {
            self::assertLessThanOrEqual($tomate['consommation_moyenne_g_jour'] * 18 + 1, $s['quantite_nette_g']);
        }
    }

    public function testAlimentNonCultivable(): void
    {
        $p = $this->previsions->previsions($this->d, 8);
        $lait = self::aliment($p, 'LAIT_POUDRE');
        self::assertFalse($lait['cultivable']);
        self::assertSame([], $lait['semis_recommandes']);
        self::assertSame(12000.0, $lait['stock_actuel_g']);

        $agri = $this->previsions->besoinsAgricoles($this->d, 1);
        self::assertNotContains('LAIT_POUDRE', array_column($agri['besoins'], 'aliment_id'));
        self::assertEqualsWithDelta(array_sum(array_column($agri['besoins'], 'surface_m2')), $agri['totaux']['surface_m2'], 0.05);
    }

    public function testSimulationDePlanning(): void
    {
        $r = $this->planning->simuler($this->d, ['equipage_ids' => [1, 2, 3], 'repas' => [
            ['date' => '2026-09-23', 'type_repas' => 'Petit-dejeuner', 'recette_id' => 5, 'portions' => 3],
            ['date' => '2026-09-24', 'type_repas' => 'Dejeuner', 'recette_id' => 9, 'portions' => 3],
        ]]);

        self::assertSame(2, $r['periode']['nb_jours']);
        $apportParEquipier = array_sum(array_map(static fn ($e) => $e['apports']['kcal'], $r['par_equipage']));
        self::assertEqualsWithDelta($r['apports_totaux_equipe']['kcal'], $apportParEquipier, 1.0);
        // L'équipier 1 est allergique au lactose : la bouillie lactée doit lever une alerte.
        self::assertNotEmpty(array_filter($r['alertes'], static fn ($a) => 'allergie' === $a['type'] && 1 === $a['equipage_id']));
    }

    public function testSimulationRefuseRecetteInconnue(): void
    {
        $this->expectException(MoteurException::class);
        $this->planning->simuler($this->d, ['equipage_ids' => [1], 'repas' => [['date' => '2026-09-23', 'type_repas' => 'Diner', 'recette_id' => 9999]]]);
    }

    public function testGenerationRespecteAllergiesEtEstReproductible(): void
    {
        $entree = ['equipage_ids' => [1, 2, 3, 4], 'date_debut' => '2026-09-23', 'horizon_jours' => 3, 'graine' => 7, 'autoriser_rupture_stock' => true];
        $a = $this->planning->generer($this->d, $entree);
        $b = $this->planning->generer($this->d, $entree);

        self::assertSame(array_column($a['repas_generes'], 'recette_choisie_id'), array_column($b['repas_generes'], 'recette_choisie_id'));
        self::assertCount(3 * 4 * 4, $a['repas_generes']); // 3 jours × 4 créneaux × 4 groupes d'allergies
        self::assertEmpty(array_filter($a['alertes'], static fn ($al) => 'allergie' === $al['type']));
        foreach ($a['repas_generes'] as $repas) {
            self::assertContains(strtolower(str_replace('-', '', $repas['type_repas'])), $this->recettes->creneaux($this->d, $this->d->recettes[$repas['recette_choisie_id']]));
        }
    }

    public function testGenerationSansStockLeveRuptureDeMenu(): void
    {
        try {
            $this->planning->generer($this->d, ['equipage_ids' => [2], 'date_debut' => '2026-09-23', 'horizon_jours' => 1, 'graine' => 1]);
            self::fail('rupture de menu attendue');
        } catch (MoteurException $e) {
            self::assertSame(409, $e->getStatutHttp());
            self::assertSame('rupture de menu', $e->getDetails()['alerte']);
        }
    }

    public function testLecturePlanning(): void
    {
        $r = $this->planning->lire($this->d, '2026-09-23', '2026-09-23', null);
        self::assertCount(3, $r['planning']);

        $this->expectExceptionObject(MoteurException::nonDisponible(''));
        try {
            $this->planning->lire($this->d, null, null, 1);
        } catch (MoteurException $e) {
            self::assertSame(501, $e->getStatutHttp());
            throw MoteurException::nonDisponible('');
        }
    }

    public function testEcartNutritionnel(): void
    {
        $e = $this->ecarts->ecart($this->d, 1, 7);
        // 20/09 : 250 g de bouillie lactée (180 kcal/100 g) + 400 g de riz aux haricots (128 kcal/100 g).
        self::assertSame(962.0, $e['par_jour'][0]['apport']['kcal']);
        self::assertEqualsWithDelta(962 / 7, $e['consomme_moyen_jour']['kcal'], 0.1);
        self::assertLessThan(0, $e['ecart']['kcal']);
    }

    private static function aliment(array $prevision, string $code): array
    {
        foreach ($prevision['aliments'] as $a) {
            if ($a['aliment_id'] === $code) {
                return $a;
            }
        }
        self::fail("$code absent de la prévision");
    }

    private static function lot(int $id, string $aliment, float $quantite, ?string $peremption): array
    {
        return [
            'id' => $id, 'aliment' => $aliment, 'quantite_initiale_g' => $quantite, 'quantite_g' => $quantite,
            'date_entree' => new \DateTimeImmutable('2026-09-01'),
            'date_peremption' => null !== $peremption ? new \DateTimeImmutable($peremption) : null,
            'type_reserve' => Parametres::RESERVE_UTILISABLE[0], 'statut' => 'frais', 'recolte_id' => null,
        ];
    }
}
