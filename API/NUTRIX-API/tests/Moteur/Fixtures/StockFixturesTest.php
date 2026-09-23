<?php

namespace App\Tests\Moteur\Fixtures;

use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\StockCalculator;
use App\Tests\Moteur\MoteurTestCase;

/**
 * Stock et autonomie (GET /api/stock/autonomie) sur les lots des fixtures, au 23/09/2026 :
 *   lot 1 TOMATE 3 200 g courante, périmé le 20/08   — lot 2 HARICOT_ROUGE 3 100 g sécurité
 *   lot 3 OEUF_POUDRE 8 500 g urgence                 — lot 4 LAIT_POUDRE 12 000 g courante
 * Récoltes à venir : RIZ 8 000 g le 01/10 (planifiée), SALADE 4 500 g prévue le 20/09 (en cours, en retard).
 */
class StockFixturesTest extends MoteurTestCase
{
    public function testSeulLeLaitEstUtilisable(): void
    {
        $d = $this->donneesFixtures();

        self::assertSame(['LAIT_POUDRE' => 12000.0], $this->stock->disponible($d, $d->aujourdhui));
        self::assertEquals([
            'LAIT_POUDRE' => ['courante' => 12000.0],
            'HARICOT_ROUGE' => ['securite' => 3100.0],
            'OEUF_POUDRE' => ['urgence' => 8500.0],
        ], $this->stock->parReserve($d, $d->aujourdhui));
        // Avant le 20/08, le lot de tomates était encore utilisable.
        self::assertSame(3200.0, $this->stock->disponible($d, new \DateTimeImmutable('2026-08-15'))['TOMATE']);
    }

    public function testSortiesFefo(): void
    {
        $d = $this->donneesFixtures();

        self::assertSame(['sorties' => [['lot_id' => 4, 'quantite_g' => 500.0, 'date_peremption' => '2027-01-15']], 'manque_g' => 0.0],
            $this->stock->planifierSortie($d, 'LAIT_POUDRE', 500, $d->aujourdhui));
        self::assertSame(1000.0, $this->stock->planifierSortie($d, 'LAIT_POUDRE', 13000, $d->aujourdhui)['manque_g']);
        // Réserves de sécurité et lots périmés ne sortent jamais en usage normal.
        self::assertSame(['sorties' => [], 'manque_g' => 100.0], $this->stock->planifierSortie($d, 'HARICOT_ROUGE', 100, $d->aujourdhui));
        self::assertSame(['sorties' => [], 'manque_g' => 100.0], $this->stock->planifierSortie($d, 'TOMATE', 100, $d->aujourdhui));

        $this->expectException(MoteurException::class);
        $this->stock->planifierSortie($d, 'LAIT_POUDRE', 0, $d->aujourdhui);
    }

    public function testUrgencePeremption(): void
    {
        $jour = new \DateTimeImmutable('2026-09-23');
        $lot = static fn (?string $date) => ['date_peremption' => null !== $date ? new \DateTimeImmutable($date) : null];

        self::assertSame(0.0, StockCalculator::urgencePeremption($lot(null), $jour));
        self::assertSame(0.0, StockCalculator::urgencePeremption($lot('2026-10-30'), $jour));
        self::assertEqualsWithDelta(1 - 3 / 7, StockCalculator::urgencePeremption($lot('2026-09-26'), $jour), 1e-9);
        self::assertSame(1.0, StockCalculator::urgencePeremption($lot('2026-09-23'), $jour));
    }

    public function testRecoltesEtTauxDePerte(): void
    {
        $d = $this->donneesFixtures();

        self::assertSame(0.06, StockCalculator::tauxPerteHistorique($d, 'TOMATE'));
        self::assertSame(0.0, StockCalculator::tauxPerteHistorique($d, 'HARICOT_ROUGE'));
        self::assertSame(0.05, StockCalculator::tauxPerteHistorique($d, 'RIZ'), 'défaut sans historique');

        // La salade en retard est attendue aujourd'hui ; le riz arrive au 8e jour.
        self::assertSame(['SALADE' => 4275.0], StockCalculator::recoltesAVenir($d, $d->aujourdhui, $d->aujourdhui->modify('+7 days')));
        self::assertSame(['RIZ' => 7600.0, 'SALADE' => 4275.0], StockCalculator::recoltesAVenir($d, $d->aujourdhui, $d->aujourdhui->modify('+8 days')));
    }

    public function testAutonomie(): void
    {
        $d = $this->donneesFixtures();
        $a = $this->stock->autonomie($d);
        $besoin = $a['besoin_journalier_equipe'];

        self::assertSame(4, $a['nb_equipiers']);
        self::assertEquals(['kcal' => 59520.0, 'proteines_g' => 3120.0, 'glucides_g' => 4560.0, 'lipides_g' => 3120.0, 'fibres_g' => 0.0], $a['stock_disponible']);
        self::assertSame(100.0, $besoin['fibres_g']);
        self::assertEqualsWithDelta(59520 / $besoin['kcal'], $a['par_nutriment']['kcal'], 0.05);
        self::assertEqualsWithDelta(3120 / $besoin['proteines_g'], $a['par_nutriment']['proteines_g'], 0.05);
        self::assertEqualsWithDelta(4560 / $besoin['glucides_g'], $a['par_nutriment']['glucides_g'], 0.05);
        self::assertSame(0.0, $a['par_nutriment']['fibres_g']);
        self::assertSame(0.0, $a['autonomie_globale_jours']);
        self::assertSame('fibres_g', $a['nutriment_limitant']);

        // Avec les récoltes : 7 600 g de riz (0,4 g de fibres/100 g) et 4 275 g de salade (1,3 g/100 g).
        $fibres = 7600 * 0.004 + 4275 * 0.013;
        self::assertSame(['RIZ' => 7600.0, 'SALADE' => 4275.0], $a['avec_recoltes_prevues']['recoltes_attendues_g']);
        self::assertEqualsWithDelta($fibres / 100, $a['avec_recoltes_prevues']['par_nutriment']['fibres_g'], 0.05);
        self::assertSame('fibres_g', $a['avec_recoltes_prevues']['nutriment_limitant']);

        // Toutes réserves : + 3 100 g de haricots (6,4 g de fibres/100 g) et 8 500 g d'oeufs.
        self::assertEqualsWithDelta(3100 * 0.064 / 100, $a['toutes_reserves']['par_nutriment']['fibres_g'], 0.05);
        self::assertGreaterThan($a['par_nutriment']['kcal'], $a['toutes_reserves']['par_nutriment']['kcal']);

        self::assertCount(1, $a['alertes']);
        self::assertSame(['niveau' => 'critique', 'type' => 'lot_perime', 'lot_id' => 1, 'aliment_id' => 'TOMATE', 'quantite_g' => 3200.0],
            array_intersect_key($a['alertes'][0], array_flip(['niveau', 'type', 'lot_id', 'aliment_id', 'quantite_g'])));
        self::assertStringContainsString('34 jour(s)', $a['alertes'][0]['message']);
    }

    public function testAlertePeremptionProche(): void
    {
        // Au 10/01/2027, lait et oeufs périment dans 5 jours.
        $d = $this->donneesFixtures('2027-01-10');
        $types = array_column($this->stock->alertesLots($d, $d->aujourdhui), 'type', 'lot_id');

        self::assertSame('lot_perime', $types[1]);
        self::assertSame('peremption_proche', $types[3]);
        self::assertSame('peremption_proche', $types[4]);
        self::assertArrayNotHasKey(2, $types);
    }
}
