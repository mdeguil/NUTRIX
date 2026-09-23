<?php

namespace App\Tests\Moteur\Fixtures;

use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\RecetteCalculator;
use App\Tests\Moteur\MoteurTestCase;

/**
 * Planning des repas sur les fixtures :
 *   POST /api/planning-repas/simuler, POST /api/planning-repas/generer,
 *   GET /api/planning-repas, POST /api/journal-repas (calcul de l'apport).
 *
 * Recettes des fixtures par créneau : petit-déjeuner 2 (omelette) et 5 (bouillie lactée),
 * déjeuner 1 (riz aux haricots), dîner 3 (purée), collation 4 (salade de tomates).
 */
class PlanningFixturesTest extends MoteurTestCase
{
    private const JOURNEE = [
        ['date' => '2026-09-23', 'type_repas' => 'Petit-dejeuner', 'recette_id' => 5, 'portions' => 4],
        ['date' => '2026-09-23', 'type_repas' => 'Dejeuner', 'recette_id' => 1, 'portions' => 4],
        ['date' => '2026-09-23', 'type_repas' => 'Diner', 'recette_id' => 3, 'portions' => 4],
        ['date' => '2026-09-23', 'type_repas' => 'Collation', 'recette_id' => 4, 'portions' => 4],
    ];

    public function testSimulationDUneJournee(): void
    {
        $d = $this->donneesFixtures();
        $r = $this->planning->simuler($d, ['equipage_ids' => [1, 2, 3, 4], 'repas' => self::JOURNEE]);

        $energieMoyenne = $this->energieMoyenne();
        $bouillie = $energieMoyenne * 0.20 / 180 * 100; // part non bornée
        // Riz, purée et salade dépassent 600 g par part : bornés à 600 g.
        $kcalParPart = $bouillie * 1.80 + 600 * 1.28 + 600 * 0.80 + 600 * 0.17;

        self::assertSame(['debut' => '2026-09-23', 'fin' => '2026-09-23', 'nb_jours' => 1], $r['periode']);
        self::assertEqualsWithDelta(4 * $kcalParPart, $r['apports_totaux_equipe']['kcal'], 0.5);
        self::assertEqualsWithDelta(100 * $kcalParPart / $energieMoyenne, $r['couverture_pct']['kcal'], 0.1);

        // La part de chacun suit son besoin : tout le monde a la même couverture énergétique.
        $couvertures = array_map(static fn ($e) => $e['couverture_pct']['kcal'], $r['par_equipage']);
        self::assertEqualsWithDelta(min($couvertures), max($couvertures), 0.15);
        self::assertEqualsWithDelta($r['apports_totaux_equipe']['kcal'], array_sum(array_map(static fn ($e) => $e['apports']['kcal'], $r['par_equipage'])), 0.5);

        $parts = array_column($r['par_equipage'][0]['par_repas'], 'portion_g', 'recette_id');
        self::assertEqualsWithDelta($bouillie * $this->ratio(1), $parts[5], 0.1);
        self::assertEqualsWithDelta(600 * $this->ratio(1), $parts[1], 0.1);

        self::assertEqualsWithDelta(4 * $bouillie, $r['ingredients_necessaires_g']['LAIT_POUDRE'], 0.1);
        self::assertEquals(['RIZ' => 1200.0, 'HARICOT_ROUGE' => 1200.0, 'POMME_TERRE' => 2400.0, 'TOMATE' => 2400.0],
            array_intersect_key($r['ingredients_necessaires_g'], array_flip(['RIZ', 'HARICOT_ROUGE', 'POMME_TERRE', 'TOMATE'])));
    }

    public function testAlertesDeSimulation(): void
    {
        $r = $this->planning->simuler($this->donneesFixtures(), ['equipage_ids' => [1, 2, 3, 4], 'repas' => self::JOURNEE]);
        $parType = [];
        foreach ($r['alertes'] as $a) {
            $parType[$a['type']][] = $a;
        }

        // Équipier 1 allergique au lactose : la bouillie lactée est signalée, et seulement pour lui.
        self::assertCount(1, $parType['allergie']);
        self::assertSame(1, $parType['allergie'][0]['equipage_id']);
        self::assertSame(5, $parType['allergie'][0]['recette_id']);

        // Riz, haricots, pommes de terre et tomates ne sont pas en réserve courante ; le lait suffit.
        self::assertEqualsCanonicalizing(['RIZ', 'HARICOT_ROUGE', 'POMME_TERRE', 'TOMATE'], array_column($parType['stock'], 'aliment_id'));
        self::assertArrayNotHasKey('creneau', $parType);
    }

    public function testRecetteHorsCreneauEtRatiosFournis(): void
    {
        $r = $this->planning->simuler($this->donneesFixtures(), ['equipage_ids' => [2, 3], 'repas' => [
            ['date' => '2026-09-23', 'type_repas' => 'Diner', 'recette_id' => 5, 'portions' => 2, 'portion_ratios' => [2 => 0.5, 3 => 1.5]],
        ]]);

        self::assertSame('creneau', $r['alertes'][0]['type']);
        $parts = array_column(array_map(static fn ($e) => $e['par_repas'][0] + ['id' => $e['equipage_id']], $r['par_equipage']), 'portion_g', 'id');
        self::assertEqualsWithDelta(3 * $parts[2], $parts[3], 0.2);
    }

    public function testEntreesInvalides(): void
    {
        $d = $this->donneesFixtures();
        $cas = [
            'équipage vide' => ['equipage_ids' => [], 'repas' => self::JOURNEE],
            'équipage inconnu' => ['equipage_ids' => [99], 'repas' => self::JOURNEE],
            'aucun repas' => ['equipage_ids' => [1], 'repas' => []],
            'recette inconnue' => ['equipage_ids' => [1], 'repas' => [['date' => '2026-09-23', 'type_repas' => 'Diner', 'recette_id' => 99]]],
            'créneau inconnu' => ['equipage_ids' => [1], 'repas' => [['date' => '2026-09-23', 'type_repas' => 'Brunch', 'recette_id' => 1]]],
            'date invalide' => ['equipage_ids' => [1], 'repas' => [['date' => '23/09/2026', 'type_repas' => 'Diner', 'recette_id' => 3]]],
            'portions négatives' => ['equipage_ids' => [1], 'repas' => [['date' => '2026-09-23', 'type_repas' => 'Diner', 'recette_id' => 3, 'portions' => -1]]],
        ];
        foreach ($cas as $nom => $entree) {
            try {
                $this->planning->simuler($d, $entree);
                self::fail("$nom : 422 attendu");
            } catch (MoteurException $e) {
                self::assertSame(422, $e->getStatutHttp(), $nom);
            }
        }
    }

    public function testGenerationChoisitLesSeulesRecettesPossibles(): void
    {
        $r = $this->planning->generer($this->donneesFixtures(), [
            'equipage_ids' => [2, 3, 4], 'date_debut' => '2026-09-23', 'horizon_jours' => 2, 'graine' => 1, 'autoriser_rupture_stock' => true,
        ]);

        // 2 jours × 4 créneaux × 3 groupes d'allergies (#2, #3 gluten, #4 arachide).
        self::assertCount(24, $r['repas_generes']);
        $attendu = ['Petit-dejeuner' => 5, 'Dejeuner' => 1, 'Diner' => 3, 'Collation' => 4];
        foreach ($r['repas_generes'] as $repas) {
            self::assertSame($attendu[$repas['type_repas']], $repas['recette_choisie_id'], $repas['date'].' '.$repas['type_repas']);
            self::assertCount(1, $repas['equipage_ids']);
        }

        $jour1 = array_values(array_filter($r['repas_generes'], static fn ($x) => '2026-09-23' === $x['date']));
        $jour2 = array_values(array_filter($r['repas_generes'], static fn ($x) => '2026-09-24' === $x['date']));
        // Jour 1 : la bouillie est faisable avec le lait en stock, sans rien assouplir.
        foreach (array_filter($jour1, static fn ($x) => 'Petit-dejeuner' === $x['type_repas']) as $x) {
            self::assertFalse($x['cooldown_releve']);
            self::assertFalse($x['rupture_stock_ignoree']);
        }
        // Jour 2 : servie la veille, elle n'est reprise qu'en levant le délai minimal (seule autre option : l'omelette, sans oeufs en stock).
        foreach (array_filter($jour2, static fn ($x) => 'Petit-dejeuner' === $x['type_repas']) as $x) {
            self::assertTrue($x['cooldown_releve']);
            self::assertFalse($x['rupture_stock_ignoree']);
        }
        // Riz, purée et salade : ingrédients absents du stock, servis uniquement parce que la rupture est autorisée.
        foreach ($r['repas_generes'] as $x) {
            self::assertSame('Petit-dejeuner' !== $x['type_repas'], $x['rupture_stock_ignoree']);
        }

        // Ordre chronologique puis par créneau, et part enregistrable pour chaque repas.
        self::assertSame(['Petit-dejeuner', 'Petit-dejeuner', 'Petit-dejeuner', 'Dejeuner'], array_column(array_slice($r['repas_generes'], 0, 4), 'type_repas'));
        self::assertCount(24, $r['repas_a_enregistrer']);
        self::assertSame([['equipage_id' => 2, 'portion_ratio' => 1.0]], $r['repas_a_enregistrer'][0]['occupants']);
        self::assertEmpty(array_filter($r['alertes'], static fn ($a) => 'allergie' === $a['type']));
    }

    public function testGenerationReproductible(): void
    {
        $entree = ['equipage_ids' => [2, 3, 4], 'date_debut' => '2026-09-23', 'horizon_jours' => 3, 'graine' => 42, 'autoriser_rupture_stock' => true];
        $a = $this->planning->generer($this->donneesFixtures(), $entree);
        $b = $this->planning->generer($this->donneesFixtures(), $entree);

        self::assertSame(json_encode($a), json_encode($b));
    }

    public function testRuptureDeMenu(): void
    {
        $d = $this->donneesFixtures();

        // Sans autoriser la rupture de stock, aucun déjeuner n'est faisable (pas de riz).
        $e = $this->conflit(fn () => $this->planning->generer($d, ['equipage_ids' => [2], 'date_debut' => '2026-09-23', 'horizon_jours' => 1, 'graine' => 1]));
        self::assertSame(['alerte' => 'rupture de menu', 'date' => '2026-09-23', 'type_repas' => 'Dejeuner', 'equipage_ids' => [2]], $e->getDetails());

        // L'équipier 1 (lactose) n'a aucun petit-déjeuner possible : les deux recettes contiennent du lait.
        $e = $this->conflit(fn () => $this->planning->generer($d, ['equipage_ids' => [1], 'date_debut' => '2026-09-23', 'horizon_jours' => 1, 'graine' => 1, 'autoriser_rupture_stock' => true]));
        self::assertSame('Petit-dejeuner', $e->getDetails()['type_repas']);
    }

    public function testGenerationEntreesInvalides(): void
    {
        $d = $this->donneesFixtures();
        foreach ([
            ['equipage_ids' => [2], 'date_debut' => '2026-09-23', 'horizon_jours' => 0],
            ['equipage_ids' => [2], 'date_debut' => '2026-09-23', 'horizon_jours' => 32],
            ['equipage_ids' => [2], 'date_debut' => 'demain'],
            ['equipage_ids' => [2], 'date_debut' => '2026-09-23', 'types_repas' => ['Goûter']],
        ] as $entree) {
            try {
                $this->planning->generer($d, $entree);
                self::fail('422 attendu : '.json_encode($entree));
            } catch (MoteurException $e) {
                self::assertSame(422, $e->getStatutHttp());
            }
        }
    }

    public function testLecturePlanning(): void
    {
        $d = $this->donneesFixtures();
        $energieMoyenne = $this->energieMoyenne();

        self::assertCount(4, $this->planning->lire($d, null, null, null)['planning']);
        $jour = $this->planning->lire($d, '2026-09-23', '2026-09-23', null)['planning'];
        self::assertSame([1, 2, 3], array_column($jour, 'id'));

        $bouillie = $energieMoyenne * 0.20 / 180 * 100;
        self::assertEqualsWithDelta($bouillie, $jour[0]['portion_standard_g'], 0.1);
        self::assertEqualsWithDelta(4 * $bouillie * 1.8, $jour[0]['apport_total']['kcal'], 0.2);
        self::assertSame(600.0, $jour[1]['portion_standard_g']);
        self::assertSame(3072.0, $jour[1]['apport_total']['kcal']); // 4 × 600 g × 128 kcal/100 g

        // Le 24/09, l'omelette est servie au déjeuner : sa part suit le coefficient du déjeuner.
        $omelette = $this->planning->lire($d, '2026-09-24', null, null)['planning'][0];
        self::assertSame('Dejeuner', $omelette['type_repas']);
        self::assertEqualsWithDelta($energieMoyenne * 0.35 / 220 * 100, $omelette['portion_standard_g'], 0.1);
    }

    public function testLectureParOccupant(): void
    {
        $d = $this->donneesFixtures();

        // Schema sans le lien (pre-migration Version20260923090000, gap #10) : filtre indisponible.
        $d->planningOccupantLie = false;
        try {
            $this->planning->lire($d, null, null, 1);
            self::fail('501 attendu');
        } catch (MoteurException $e) {
            self::assertSame(501, $e->getStatutHttp(), 'PLANNING_REPAS_OCCUPANT sans Id_PLANNING_REPAS');
        }

        // Une fois le lien ajouté en base (schema actuel), le filtre renvoie la part de l'occupant.
        $d->planningOccupantLie = true;
        foreach ($d->planning as $i => $p) {
            $d->planning[$i]['occupants'] = 1 === $p['id'] ? [['equipage_id' => 3, 'portion_ratio' => 0.8]] : [];
        }
        $lignes = $this->planning->lire($d, null, null, 3)['planning'];
        self::assertCount(1, $lignes);
        self::assertEqualsWithDelta($lignes[0]['portion_standard_g'] * 0.8 * 1.8, $lignes[0]['apport_occupant']['kcal'], 0.2);
    }

    public function testApportDUnRepasJournalise(): void
    {
        $d = $this->donneesFixtures();
        $r = $this->planning->repasJournal($d, ['equipage_id' => 1, 'recette_id' => 5, 'type_repas_id' => 1, 'date_heure' => '2026-09-23T07:30:00', 'portion_g' => 250]);

        self::assertSame('2026-09-23 07:30:00', $r['date_heure']->format('Y-m-d H:i:s'));
        self::assertEquals(['kcal' => 450.0, 'proteines_g' => 22.5, 'glucides_g' => 45.0, 'lipides_g' => 22.5, 'fibres_g' => 0.0], $r['apport']);

        // Sans portion_g : part standard calculée sur le besoin de l'équipier.
        $sansPart = $this->planning->repasJournal($d, ['equipage_id' => 1, 'recette_id' => 5, 'type_repas_id' => 1, 'date_heure' => '2026-09-23T07:30:00']);
        $besoin = $this->besoins->besoinIndividuel($d->equipages[1])['energy_kcal'];
        self::assertEqualsWithDelta(RecetteCalculator::portionG($d->recettes[5], 'petitdejeuner', $besoin), $sansPart['portion_g'], 0.01);

        foreach ([['recette_id' => 99], ['equipage_id' => 99], ['type_repas_id' => 9], ['portion_g' => -5]] as $modif) {
            try {
                $this->planning->repasJournal($d, $modif + ['equipage_id' => 1, 'recette_id' => 5, 'type_repas_id' => 1]);
                self::fail('422 attendu : '.json_encode($modif));
            } catch (MoteurException $e) {
                self::assertSame(422, $e->getStatutHttp());
            }
        }
    }

    private function energieMoyenne(): float
    {
        $besoins = $this->besoins->besoinsEquipage($this->donneesFixtures());

        return array_sum(array_column($besoins, 'energy_kcal')) / count($besoins);
    }

    private function ratio(int $id): float
    {
        return $this->besoins->besoinsEquipage($this->donneesFixtures())[$id]['energy_kcal'] / $this->energieMoyenne();
    }

    private function conflit(callable $appel): MoteurException
    {
        try {
            $appel();
        } catch (MoteurException $e) {
            self::assertSame(409, $e->getStatutHttp());

            return $e;
        }
        self::fail('409 rupture de menu attendu');
    }
}
