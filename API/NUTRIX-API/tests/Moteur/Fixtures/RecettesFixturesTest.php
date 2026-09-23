<?php

namespace App\Tests\Moteur\Fixtures;

use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\RecetteCalculator;
use App\Tests\Moteur\MoteurTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Recettes des fixtures (GET /api/recettes/{id}/profil-nutritionnel, composition, allergènes, créneaux).
 * Les 5 recettes ont toutes quantite_g = 0 dans recette_ingredient.
 */
class RecettesFixturesTest extends MoteurTestCase
{
    public static function recettes(): iterable
    {
        // id => [kcal/100 g, composition attendue, mode, allergènes, créneaux]
        yield 'riz aux haricots : aucune sortie liée, répartition uniforme' => [1, 128.0, ['RIZ' => 50.0, 'HARICOT_ROUGE' => 50.0], RecetteCalculator::MODE_UNIFORME, [], ['dejeuner']];
        yield 'omelette : oeuf + lait' => [2, 220.0, ['OEUF_POUDRE' => 50.0, 'LAIT_POUDRE' => 50.0], RecetteCalculator::MODE_UNIFORME, [2], ['petitdejeuner']];
        yield 'purée : pomme de terre seule' => [3, 80.0, ['POMME_TERRE' => 100.0], RecetteCalculator::MODE_UNIFORME, [], ['diner']];
        yield 'salade de tomates : sortie de stock de tomates liée (Asso_11)' => [4, 17.0, ['TOMATE' => 100.0], RecetteCalculator::MODE_EMPIRIQUE, [], ['collation']];
        yield 'bouillie lactée : lait seul' => [5, 180.0, ['LAIT_POUDRE' => 100.0], RecetteCalculator::MODE_UNIFORME, [2], ['petitdejeuner']];
    }

    /**
     * @param array<string, float> $composition
     * @param list<int>            $allergenes
     * @param list<string>         $creneaux
     */
    #[DataProvider('recettes')]
    public function testRecette(int $id, float $kcal, array $composition, string $mode, array $allergenes, array $creneaux): void
    {
        $d = $this->donneesFixtures();
        $recette = $d->recettes[$id];

        self::assertSame($kcal, $recette['pour100g']['kcal']);
        self::assertEquals(['mode' => $mode, 'aliments' => $composition], $this->recettes->composition($d, $recette));
        self::assertSame($allergenes, $this->recettes->allergenes($d, $recette));
        self::assertSame($creneaux, $this->recettes->creneaux($d, $recette));

        $profil = $this->recettes->profilNutritionnel($d, $id);
        self::assertFalse($profil['recompute_disponible']);
        self::assertNull($profil['valeur_recalculee_ingredients']);
        self::assertArrayHasKey('warning', $profil);
        self::assertSame($kcal, $profil['valeur_stockee']['kcal']);
    }

    public function testProfilRecalculeQuandLesQuantitesExistent(): void
    {
        $d = $this->donneesFixtures();
        $d->recettes[1]['ingredients'] = ['RIZ' => 300.0, 'HARICOT_ROUGE' => 150.0];

        $profil = $this->recettes->profilNutritionnel($d, 1);
        $attendu = (300 * 130 + 150 * 127) / 450; // kcal pour 100 g
        self::assertTrue($profil['recompute_disponible']);
        self::assertEqualsWithDelta($attendu, $profil['valeur_recalculee_ingredients']['kcal'], 0.05);
        self::assertEqualsWithDelta(100 * ($attendu - 128) / 128, $profil['ecart_pct']['kcal'], 0.1);
        self::assertSame(RecetteCalculator::MODE_NOMINAL, $this->recettes->composition($d, $d->recettes[1])['mode']);
    }

    public function testRecetteInconnue(): void
    {
        $this->expectExceptionObject(MoteurException::introuvable('Recette 99 introuvable'));
        $this->recettes->profilNutritionnel($this->donneesFixtures(), 99);
    }

    public function testApportEtTailleDePart(): void
    {
        $d = $this->donneesFixtures();
        self::assertEquals(['kcal' => 450.0, 'proteines_g' => 22.5, 'glucides_g' => 45.0, 'lipides_g' => 22.5, 'fibres_g' => 0.0], $this->recettes->apport($d->recettes[5], 250));

        // Part = énergie du créneau / densité énergétique, bornée à [100 ; 600] g.
        self::assertEqualsWithDelta(2000 * 0.20 / 180 * 100, RecetteCalculator::portionG($d->recettes[5], 'petitdejeuner', 2000), 0.001);
        self::assertSame(600.0, RecetteCalculator::portionG($d->recettes[4], 'collation', 2000));
        self::assertSame(100.0, RecetteCalculator::portionG($d->recettes[2], 'collation', 500));
    }
}
