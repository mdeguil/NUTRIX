<?php

namespace App\Tests\Moteur\Fixtures;

use App\Service\Moteur\Exception\MoteurException;
use App\Tests\Moteur\MoteurTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Besoins nutritionnels (GET /api/equipages/{id}/besoins, /equipages/besoins, /equipages/besoins/creneau)
 * sur l'équipage des fixtures :
 *   #1 homme 34 ans 78,5 kg PAL 1,6 (lactose) — #2 femme 29 ans 62 kg PAL 1,4
 *   #3 homme 45 ans 82 kg PAL 1,2 (gluten)    — #4 femme 27 ans 58,5 kg PAL 1,9 (arachide)
 */
class BesoinsFixturesTest extends MoteurTestCase
{
    private const KCAL_PAR_MJ = 238.83;

    /** Énergie attendue (MJ) : valeur EFSA directe, ou dépense de repos × PAL quand le PAL n'est pas tabulé. */
    public static function energies(): iterable
    {
        yield 'équipier 1, PAL tabulé' => [1, 10.8];
        yield 'équipier 2, PAL tabulé' => [2, 7.9];
        yield 'équipier 3, PAL 1,2 extrapolé' => [3, (9.3 / 1.4 + 10.7 / 1.6 + 12.0 / 1.8 + 13.4 / 2.0) / 4 * 1.2];
        yield 'équipier 4, PAL 1,9 extrapolé' => [4, (7.9 / 1.4 + 9.0 / 1.6 + 10.1 / 1.8 + 11.2 / 2.0) / 4 * 1.9];
    }

    #[DataProvider('energies')]
    public function testEnergieEtMacronutriments(int $id, float $mj): void
    {
        $b = $this->besoins->besoinEquipier($this->donneesFixtures(), $id);
        $kcal = $mj * self::KCAL_PAR_MJ;

        self::assertEqualsWithDelta($kcal, $b['energy_kcal'], 0.1);
        self::assertEqualsWithDelta($kcal * 0.45 / 4, $b['carbohydrates_g']['min'], 0.1);
        self::assertEqualsWithDelta($kcal * 0.60 / 4, $b['carbohydrates_g']['max'], 0.1);
        self::assertEqualsWithDelta($kcal * 0.20 / 9, $b['lipids_g']['min'], 0.1);
        self::assertEqualsWithDelta($kcal * 0.35 / 9, $b['lipids_g']['max'], 0.1);
        self::assertEqualsWithDelta(1.6 * $mj, $b['vitamins']['niacin_mg'], 0.01);
        self::assertEqualsWithDelta(0.1 * $mj, $b['vitamins']['thiamin_mg'], 0.01);
        self::assertSame(25.0, $b['fiber_g']);
    }

    public function testValeursDependantDuSexeEtDuPoids(): void
    {
        $d = $this->donneesFixtures();
        $homme = $this->besoins->besoinEquipier($d, 1);
        $femme = $this->besoins->besoinEquipier($d, 2);

        self::assertEqualsWithDelta(0.83 * 78.5, $homme['protein_g'], 0.01);
        self::assertEqualsWithDelta(0.83 * 62.0, $femme['protein_g'], 0.01);
        self::assertSame(2.5, $homme['water_l']);
        self::assertSame(2.0, $femme['water_l']);
        self::assertSame(11.0, $homme['minerals']['iron_mg']);
        self::assertSame(16.0, $femme['minerals']['iron_mg']);
        self::assertSame(11.7, $homme['minerals']['zinc_mg']);
        self::assertSame(9.3, $femme['minerals']['zinc_mg']);
        self::assertSame(110.0, $homme['vitamins']['vitamin_c_mg']);
        self::assertSame(95.0, $femme['vitamins']['vitamin_c_mg']);
        self::assertSame(950.0, $homme['minerals']['calcium_mg']);
        self::assertSame('F', $femme['meta']['sex_used']);
        self::assertSame('premenopausal', $femme['meta']['menopause_status_assumption']);
        self::assertNull($homme['meta']['menopause_status_assumption']);
    }

    public function testSurchargesZincEtFer(): void
    {
        $d = $this->donneesFixtures();
        $b = $this->besoins->besoinEquipier($d, 2, 1200.0, 'postmenopausal');

        self::assertSame(12.7, $b['minerals']['zinc_mg']);
        self::assertSame(11.0, $b['minerals']['iron_mg']);
        self::assertNull($b['meta']['lpi_mg_assumption']);
        self::assertSame([], $b['meta']['hypotheses']);
    }

    public function testStatutMenopauseInvalide(): void
    {
        $this->expectException(MoteurException::class);
        $this->besoins->besoinEquipier($this->donneesFixtures(), 2, null, 'inconnu');
    }

    public function testEquipageInconnu(): void
    {
        try {
            $this->besoins->besoinEquipier($this->donneesFixtures(), 42);
            self::fail('404 attendu');
        } catch (MoteurException $e) {
            self::assertSame(404, $e->getStatutHttp());
        }
    }

    public function testBesoinEquipeEstLaSommeDesEquipiers(): void
    {
        $d = $this->donneesFixtures();
        $r = $this->besoins->besoinEquipe($d, new \DateTimeImmutable('2026-10-01'));

        self::assertSame('2026-10-01', $r['date']);
        self::assertSame([1, 2, 3, 4], array_column($r['par_equipage'], 'equipage_id'));
        $total = $r['besoin_equipe_total'];
        foreach (['energy_kcal', 'protein_g', 'fiber_g', 'water_l'] as $cle) {
            self::assertEqualsWithDelta(array_sum(array_map(static fn ($e) => $e['besoin'][$cle], $r['par_equipage'])), $total[$cle], 0.5, $cle);
        }
        self::assertEqualsWithDelta(array_sum(array_map(static fn ($e) => $e['besoin']['lipids_g']['min'], $r['par_equipage'])), $total['lipids_g']['min'], 0.1);
        self::assertSame(9.0, $total['water_l']);
        self::assertSame(100.0, $total['fiber_g']);
        self::assertSame(4, $total['meta']['nb_equipiers']);
    }

    public function testBesoinParCreneauEtGroupesAllergies(): void
    {
        $d = $this->donneesFixtures();
        $jour = $this->besoins->besoinEquipe($d, $d->aujourdhui)['besoin_equipe_total'];
        $r = $this->besoins->besoinCreneau($d, $d->aujourdhui, 'Dejeuner');

        self::assertEqualsWithDelta($jour['energy_kcal'] * 0.35, $r['besoin_creneau_equipe']['energy_kcal'], 0.2);
        self::assertEqualsWithDelta($jour['protein_g'] * 0.35, $r['besoin_creneau_equipe']['protein_g'], 0.05);
        self::assertSame(9.0 / 4, $r['besoin_creneau_equipe']['water_l']);
        self::assertSame(0.35, $r['besoin_creneau_equipe']['meta']['coefficient']);

        // Un groupe par profil d'allergies : #2 sans allergie, #3 gluten, #1 lactose, #4 arachide.
        self::assertSame([[2], [3], [1], [4]], array_column($r['groupes'], 'equipage_ids'));
        self::assertSame([[], ['Gluten'], ['Lactose'], ['Arachide']], array_column($r['groupes'], 'allergenes'));

        $individuel = $this->besoins->besoinEquipier($d, 1)['energy_kcal'];
        self::assertEqualsWithDelta($individuel * 0.35, $r['groupes'][2]['besoin_creneau']['energy_kcal'], 0.1);
    }

    public function testCoefficientsDesCreneaux(): void
    {
        $d = $this->donneesFixtures();
        $jour = $this->besoins->besoinEquipe($d, $d->aujourdhui)['besoin_equipe_total']['energy_kcal'];
        $total = 0.0;
        foreach (['Petit-dejeuner' => 0.20, 'Dejeuner' => 0.35, 'Diner' => 0.35, 'Collation' => 0.10] as $type => $coefficient) {
            $kcal = $this->besoins->besoinCreneau($d, $d->aujourdhui, $type)['besoin_creneau_equipe']['energy_kcal'];
            self::assertEqualsWithDelta($jour * $coefficient, $kcal, 0.2, $type);
            $total += $kcal;
        }
        self::assertEqualsWithDelta($jour, $total, 0.5, 'les 4 créneaux couvrent la journée');
    }

    public function testCreneauInconnu(): void
    {
        $d = $this->donneesFixtures();
        try {
            $this->besoins->besoinCreneau($d, $d->aujourdhui, 'Brunch');
            self::fail('422 attendu');
        } catch (MoteurException $e) {
            self::assertSame(422, $e->getStatutHttp());
        }
    }
}
