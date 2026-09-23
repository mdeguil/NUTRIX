<?php

namespace App\Tests\Moteur\Fixtures;

use App\Service\Moteur\Exception\MoteurException;
use App\Tests\Moteur\MoteurTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Prévision sur 8 semaines et plan de semis (GET /api/stock/previsions, GET /api/stock/besoins-agricoles).
 *
 * Demande attendue avec les fixtures (4 équipiers, parts de 600 g quand la part dépasse la borne) :
 *   déjeuner = riz aux haricots → 1 200 g de riz et 1 200 g de haricots par jour (sauf le 24/09, omelette planifiée) ;
 *   dîner = purée → 2 400 g de pommes de terre par jour ; collation = salade de tomates → 2 400 g de tomates par jour.
 */
class PrevisionFixturesTest extends MoteurTestCase
{
    private function prevision(int $semaines = 8): array
    {
        return $this->previsions->previsions($this->donneesFixtures(), $semaines);
    }

    private static function aliment(array $prevision, string $code): array
    {
        foreach ($prevision['aliments'] as $a) {
            if ($a['aliment_id'] === $code) {
                return $a;
            }
        }
        self::fail("$code absent");
    }

    public static function cultures(): iterable
    {
        // code => [consommation g/jour, cycle (jours), conservation (jours)]
        yield 'riz' => ['RIZ', 1200 * 55 / 56, 110, null];
        yield 'haricot rouge' => ['HARICOT_ROUGE', 1200 * 55 / 56, 80, 365];
        yield 'pomme de terre' => ['POMME_TERRE', 2400.0, 85, null];
        yield 'tomate' => ['TOMATE', 2400.0, 70, 18];
    }

    #[DataProvider('cultures')]
    public function testSeuilsDeStock(string $code, float $conso, int $cycle, ?int $conservation): void
    {
        $a = self::aliment($this->prevision(), $code);

        self::assertTrue($a['cultivable']);
        self::assertSame($cycle, $a['delai_obtention_jours']);
        self::assertSame($conservation, $a['duree_conservation_jours']);
        self::assertEqualsWithDelta($conso, $a['consommation_moyenne_g_jour'], 0.1);
        self::assertEqualsWithDelta(7 * $conso, $a['seuils']['stock_minimum_g'], 0.5);
        self::assertEqualsWithDelta((7 + $cycle) * $conso, $a['seuils']['stock_alerte_g'], 0.5);
        // Maximum : 7 + 14 jours de consommation, sans dépasser ce qui se mange avant péremption.
        self::assertEqualsWithDelta(min(21, $conservation ?? 21) * $conso, $a['seuils']['stock_maximum_g'], 0.5);
        self::assertSame('rupture', $a['statut']);
        self::assertSame(1, $a['semis_recommandes'][0]['semaine'], 'semis immédiat');
    }

    public function testPlanDeSemisDuRiz(): void
    {
        $riz = self::aliment($this->prevision(), 'RIZ');
        $conso = 1200 * 55 / 56;
        $semis = $riz['semis_recommandes'][0];

        self::assertSame('2026-09-23', $semis['date_semis']);
        self::assertSame('2027-01-11', $semis['date_recolte_prevue']); // + 110 jours
        // Remonter au stock maximum le jour de la récolte (stock nul, 1 200 g consommés ce jour-là).
        self::assertEqualsWithDelta(21 * $conso + 1200, $semis['quantite_nette_g'], 0.5);
        self::assertSame(0.05, $semis['taux_perte_retenu']);
        self::assertEqualsWithDelta($semis['quantite_nette_g'] / 0.95, $semis['quantite_a_produire_g'], 0.5);
        self::assertEqualsWithDelta($semis['quantite_a_produire_g'] / (2.0 * 110), $semis['surface_m2'], 0.01);
        self::assertEqualsWithDelta($semis['surface_m2'] * 25, $semis['semences_necessaires'], 1);
        self::assertEqualsWithDelta($semis['surface_m2'] * 110 * 3.0, $semis['eau_l'], 1);
        self::assertEqualsWithDelta($semis['surface_m2'] * 110 * 0.25, $semis['energie_kwh'], 0.5);

        // La récolte de riz planifiée (8 000 g − 5 %) arrive en semaine 2 et couvre ~6 jours.
        self::assertSame(7600.0, $riz['en_culture_g']);
        self::assertSame(7600.0, $riz['semaines'][1]['recoltes_g']);
        self::assertSame('2026-09-23', $riz['rupture_sans_plan']);
    }

    public function testQuantiteDeTomatesLimiteeParLaConservation(): void
    {
        $tomate = self::aliment($this->prevision(), 'TOMATE');

        foreach ($tomate['semis_recommandes'] as $s) {
            self::assertLessThanOrEqual(18 * 2400 + 0.1, $s['quantite_nette_g']);
            self::assertSame(0.06, $s['taux_perte_retenu'], 'taux de perte historique des tomates');
        }
        self::assertStringContainsString('conservation', $tomate['semis_recommandes'][0]['raison']);
    }

    public function testAlimentsNonCultivables(): void
    {
        $p = $this->prevision();
        $lait = self::aliment($p, 'LAIT_POUDRE');
        $oeuf = self::aliment($p, 'OEUF_POUDRE');

        foreach ([$lait, $oeuf] as $a) {
            self::assertFalse($a['cultivable']);
            self::assertSame([], $a['semis_recommandes']);
            self::assertSame(14, $a['delai_obtention_jours']);
        }

        // Lait : 12 000 g consommés au rythme des petits-déjeuners (bouillie et omelette).
        $d = $this->donneesFixtures();
        $besoins = $this->besoins->besoinsEquipage($d);
        $e = array_sum(array_column($besoins, 'energy_kcal')) / 4;
        $partOmelette = $e * 0.20 / 220 * 100;
        $partBouillie = $e * 0.20 / 180 * 100;
        $demande = [4 * $partBouillie, $partOmelette + 2 * $partBouillie + 2 * ($e * 0.35 / 220 * 100)];
        $stock = 12000.0;
        for ($jour = 0; $stock >= ($demande[$jour] ?? $partOmelette + 2 * $partBouillie); ++$jour) {
            $stock -= $demande[$jour] ?? $partOmelette + 2 * $partBouillie;
        }
        self::assertSame((new \DateTimeImmutable('2026-09-23'))->modify("+$jour days")->format('Y-m-d'), $lait['rupture_sans_plan']);

        // Oeufs : tout le stock est en réserve d'urgence, la réserve courante est vide dès le 24/09 (1er petit-déjeuner avec omelette).
        self::assertSame(['urgence' => 8500.0], $oeuf['reserves_g']);
        self::assertSame(0.0, $oeuf['stock_actuel_g']);
        self::assertSame('2026-09-24', $oeuf['rupture_sans_plan']);

        $approvisionnement = array_column(array_filter($p['alertes'], static fn ($a) => 'approvisionnement' === $a['type']), 'aliment_id');
        self::assertEqualsCanonicalizing(['LAIT_POUDRE', 'OEUF_POUDRE'], $approvisionnement);
    }

    public function testRuptureAvantRecolteEtReserveMobilisable(): void
    {
        $p = $this->prevision();
        $alertes = array_values(array_filter($p['alertes'], static fn ($a) => 'rupture_avant_recolte' === $a['type'] && 'HARICOT_ROUGE' === $a['aliment_id']));

        self::assertCount(1, $alertes);
        self::assertSame(3100.0, $alertes[0]['reserve_mobilisable_g'], 'haricots en réserve de sécurité');
        self::assertSame('critique', $alertes[0]['niveau']);
    }

    public function testSaladeSansConsommation(): void
    {
        $salade = self::aliment($this->prevision(), 'SALADE');

        self::assertSame('sans_consommation_prevue', $salade['statut']);
        self::assertSame(4275.0, $salade['en_culture_g']);
        self::assertSame([], $salade['semis_recommandes']);
        self::assertSame(4275.0, $salade['semaines'][0]['recoltes_g'], 'récolte en retard attendue immédiatement');
    }

    public function testVueParNutrimentEtSynthese(): void
    {
        $p = $this->prevision();
        $d = $this->donneesFixtures();
        $besoinJour = array_sum(array_column($this->besoins->besoinsEquipage($d), 'energy_kcal'));

        self::assertCount(8, $p['semaines']);
        self::assertSame(['2026-09-23', '2026-09-29'], [$p['semaines'][0]['debut'], $p['semaines'][0]['fin']]);
        self::assertEqualsWithDelta(7 * $besoinJour, $p['semaines'][0]['besoin']['kcal'], 0.5);
        self::assertSame(641.3, $p['semaines'][0]['recolte_prevue']['kcal']); // 4 275 g de salade × 15 kcal/100 g
        self::assertSame(9880.0, $p['semaines'][1]['recolte_prevue']['kcal']); // 7 600 g de riz × 130 kcal/100 g
        foreach ($p['semaines'] as $s) {
            self::assertTrue($s['periode_a_risque']);
            self::assertEqualsWithDelta(max(0, $s['besoin']['kcal'] - ($s['stock_debut']['kcal'] + $s['recolte_prevue']['kcal'] - $s['pertes_peremption']['kcal'])), $s['deficit']['kcal'], 0.5);
        }

        self::assertSame(['HARICOT_ROUGE', 'POMME_TERRE', 'RIZ', 'TOMATE'], $p['synthese_semis'][0]['aliments']);
        self::assertEqualsWithDelta(array_sum(array_column(array_filter($p['plan_semis'], static fn ($s) => 1 === $s['semaine']), 'surface_m2')), $p['synthese_semis'][0]['surface_m2'], 0.05);
        self::assertSame(4, $p['nb_equipiers']);
    }

    public function testStockMaintenuEntreMinimumEtMaximumApresLesPremieresRecoltes(): void
    {
        // Sur 30 semaines, toutes les cultures ont eu le temps de produire.
        $p = $this->prevision(30);
        foreach (['RIZ', 'HARICOT_ROUGE', 'POMME_TERRE', 'TOMATE'] as $code) {
            $a = self::aliment($p, $code);
            $premiereRecolte = new \DateTimeImmutable($a['semis_recommandes'][0]['date_recolte_prevue']);
            $verifiees = 0;
            foreach ($a['semaines'] as $s) {
                if (new \DateTimeImmutable($s['debut']) <= $premiereRecolte->modify('+7 days')) {
                    continue;
                }
                ++$verifiees;
                self::assertSame(0.0, $s['manque_g'], "$code semaine {$s['semaine']} : pas de rupture");
                self::assertGreaterThanOrEqual($a['seuils']['stock_minimum_g'] - 0.5, $s['stock_fin_g'], "$code semaine {$s['semaine']} : au-dessus du minimum");
                self::assertLessThanOrEqual($a['seuils']['stock_maximum_g'] + 0.5, $s['stock_fin_g'], "$code semaine {$s['semaine']} : sous le maximum");
            }
            self::assertGreaterThan(5, $verifiees, $code);
        }
    }

    public function testBesoinsAgricoles(): void
    {
        $d = $this->donneesFixtures();
        $semaine1 = $this->previsions->besoinsAgricoles($d, 1);

        self::assertSame(['HARICOT_ROUGE', 'POMME_TERRE', 'RIZ', 'TOMATE'], array_column($semaine1['besoins'], 'aliment_id'));
        foreach (['surface_m2', 'eau_l', 'energie_kwh'] as $cle) {
            self::assertEqualsWithDelta(array_sum(array_column($semaine1['besoins'], $cle)), $semaine1['totaux'][$cle], 0.5, $cle);
        }
        self::assertEqualsCanonicalizing(['LAIT_POUDRE', 'OEUF_POUDRE'], array_column($semaine1['hors_culture'], 'aliment_id'));

        $toutes = $this->previsions->besoinsAgricoles($d);
        self::assertSame(array_column($this->prevision()['plan_semis'], 'aliment_id'), array_column($toutes['besoins'], 'aliment_id'));

        foreach ([0, 9] as $semaine) {
            try {
                $this->previsions->besoinsAgricoles($d, $semaine);
                self::fail('422 attendu');
            } catch (MoteurException $e) {
                self::assertSame(422, $e->getStatutHttp());
            }
        }
    }

    public function testPrevisionReproductibleEtLieeALaDate(): void
    {
        self::assertSame(json_encode($this->prevision()), json_encode($this->prevision()));

        $plusTard = $this->previsions->previsions($this->donneesFixtures('2026-10-15'), 8);
        self::assertSame('2026-10-15', $plusTard['date_debut']);
        // Toujours « Planifiée » en base au 15/10 : la récolte du 01/10 est en retard, donc attendue immédiatement.
        $riz = self::aliment($plusTard, 'RIZ');
        self::assertSame(7600.0, $riz['en_culture_g']);
        self::assertSame(7600.0, $riz['semaines'][0]['recoltes_g']);
    }

    public function testNombreDeSemainesInvalide(): void
    {
        $this->expectException(MoteurException::class);
        $this->previsions->previsions($this->donneesFixtures(), 0);
    }
}
