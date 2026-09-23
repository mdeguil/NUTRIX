<?php

namespace App\Tests\Moteur;

use App\Service\Moteur\BesoinsCalculator;
use App\Service\Moteur\EcartCalculator;
use App\Service\Moteur\PlanningCalculator;
use App\Service\Moteur\PrevisionCalculator;
use App\Service\Moteur\RecetteCalculator;
use App\Service\Moteur\StockCalculator;
use App\Service\Moteur\Support\EfsaReference;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests unitaires du moteur de calcul sur les données de AppFixtures (voir FixturesNutrixTrait).
 * La date du jour est figée (DATE_REFERENCE) : les résultats sont reproductibles.
 */
abstract class MoteurTestCase extends TestCase
{
    use FixturesNutrixTrait;

    public const DATE_REFERENCE = '2026-09-23';

    protected BesoinsCalculator $besoins;
    protected RecetteCalculator $recettes;
    protected StockCalculator $stock;
    protected PrevisionCalculator $previsions;
    protected EcartCalculator $ecarts;
    protected PlanningCalculator $planning;

    protected function setUp(): void
    {
        $this->besoins = new BesoinsCalculator(new EfsaReference(dirname(__DIR__, 2).'/config/nutrix/efsa_drv_reference.json'));
        $this->recettes = new RecetteCalculator();
        $this->stock = new StockCalculator($this->besoins);
        $this->previsions = new PrevisionCalculator($this->besoins, $this->recettes, $this->stock);
        $this->ecarts = new EcartCalculator($this->besoins, $this->recettes);
        $this->planning = new PlanningCalculator($this->besoins, $this->recettes, $this->stock, $this->ecarts, $this->previsions);
    }
}
