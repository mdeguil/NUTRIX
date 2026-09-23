<?php

namespace App\Tests\Moteur\Fixtures;

use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\Support\Parametres;
use App\Tests\Moteur\MoteurTestCase;

/**
 * Écart réel / théorique (GET /api/equipages/{id}/ecart-nutritionnel) et rétroaction sur le planning.
 * Journal des fixtures : #1 le 20/09 (250 g de bouillie lactée + 400 g de riz aux haricots),
 * #2 le 20/09 (300 g de purée), #3 le 21/09 (200 g de purée), #4 le 21/09 (250 g de salade de tomates).
 */
class EcartFixturesTest extends MoteurTestCase
{
    public function testEcartSurSeptJours(): void
    {
        $d = $this->donneesFixtures();
        $e = $this->ecarts->ecart($d, 1, 7);
        $besoin = $this->besoins->besoinIndividuel($d->equipages[1])['energy_kcal'];

        self::assertSame(['2026-09-17', '2026-09-23'], [$e['du'], $e['au']]);
        self::assertSame(1, $e['jours_journalises']);
        // 250 g × (180 kcal ; 9 g prot. ; 18 g gluc. ; 9 g lip.) + 400 g × (128 ; 5,5 ; 25 ; 0,4 ; 3,5) pour 100 g.
        self::assertEquals(['kcal' => 962.0, 'proteines_g' => 44.5, 'glucides_g' => 145.0, 'lipides_g' => 24.1, 'fibres_g' => 14.0], $e['par_jour'][0]['apport']);
        self::assertSame(2, $e['par_jour'][0]['nb_repas']);
        self::assertEqualsWithDelta(962 / 7, $e['consomme_moyen_jour']['kcal'], 0.05);
        self::assertEqualsWithDelta(962 / 7 - $besoin, $e['ecart']['kcal'], 0.1);
        self::assertEqualsWithDelta(100 * (962 / 7 - $besoin) / $besoin, $e['ecart_pct']['kcal'], 0.1);
        self::assertEquals(new \stdClass(), $e['retroaction_appliquee'], 'une seule journée journalisée');
    }

    public function testPeriodeSansRepas(): void
    {
        $e = $this->ecarts->ecart($this->donneesFixtures(), 2, 1);

        self::assertSame(0, $e['jours_journalises']);
        self::assertSame(0.0, $e['consomme_moyen_jour']['kcal']);
        self::assertSame(-100.0, $e['ecart_pct']['kcal']);
    }

    public function testChaqueEquipierVoitSesRepas(): void
    {
        $d = $this->donneesFixtures();
        $attendu = [2 => 300 * 0.80, 3 => 200 * 0.80, 4 => 250 * 0.17];
        foreach ($attendu as $id => $kcal) {
            $e = $this->ecarts->ecart($d, $id, 7);
            self::assertSame($kcal, $e['par_jour'][0]['apport']['kcal'], "équipier $id");
        }
    }

    public function testRetroactionApresTroisJoursDeDeficit(): void
    {
        $d = $this->donneesFixtures();
        foreach (['2026-09-20', '2026-09-21', '2026-09-22'] as $i => $jour) {
            $d->journal[] = ['id' => 100 + $i, 'date_heure' => new \DateTimeImmutable("$jour 12:00"), 'portion_g' => 100.0, 'recette_id' => 3, 'equipage_id' => 2, 'type_repas_id' => 2];
        }

        $e = $this->ecarts->ecart($d, 2, 7);
        self::assertSame(3, $e['jours_journalises']);
        self::assertTrue($e['retroaction_appliquee']['kcal']['poids_majore']);
        self::assertSame(Parametres::RETROACTION_FACTEUR, $e['retroaction_appliquee']['kcal']['facteur']);

        // Le planning du 23/09 majore alors ces nutriments pour le groupe de l'équipier 2.
        $poids = $this->ecarts->poidsNutriments($d, [2], $d->aujourdhui);
        self::assertSame(array_fill_keys(['kcal', 'proteines_g', 'glucides_g', 'lipides_g', 'fibres_g'], Parametres::RETROACTION_FACTEUR), $poids);
        self::assertSame(1.0, $this->ecarts->poidsNutriments($d, [3], $d->aujourdhui)['kcal']);
    }

    public function testJoursNonConsecutifsNeDeclenchentPasLaRetroaction(): void
    {
        $d = $this->donneesFixtures();
        // Avec le repas du 20/09 des fixtures : 18, 20 et 22/09, jamais deux jours de suite.
        foreach (['2026-09-18', '2026-09-22'] as $i => $jour) {
            $d->journal[] = ['id' => 100 + $i, 'date_heure' => new \DateTimeImmutable("$jour 12:00"), 'portion_g' => 100.0, 'recette_id' => 3, 'equipage_id' => 2, 'type_repas_id' => 2];
        }

        self::assertEquals(new \stdClass(), $this->ecarts->ecart($d, 2, 7)['retroaction_appliquee']);
    }

    public function testErreurs(): void
    {
        $d = $this->donneesFixtures();
        foreach ([[99, 7, 404], [1, 0, 422], [1, 400, 422]] as [$id, $periode, $code]) {
            try {
                $this->ecarts->ecart($d, $id, $periode);
                self::fail("$code attendu");
            } catch (MoteurException $e) {
                self::assertSame($code, $e->getStatutHttp());
            }
        }
    }
}
