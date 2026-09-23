<?php

namespace App\Tests\Moteur\Integration;

use App\DataFixtures\AppFixtures;
use App\Service\Moteur\BesoinsCalculator;
use App\Service\Moteur\Donnees\NutrixDataProvider;
use App\Service\Moteur\EcartCalculator;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\MoteurCalcul;
use App\Service\Moteur\PlanningCalculator;
use App\Service\Moteur\PrevisionCalculator;
use App\Service\Moteur\RecetteCalculator;
use App\Service\Moteur\StockCalculator;
use App\Service\Moteur\Support\EfsaReference;
use App\Tests\Moteur\FixturesNutrixTrait;
use App\Tests\Moteur\MoteurTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Moteur de calcul branché sur une vraie base MySQL remplie par AppFixtures.
 *
 * Vérifie que la lecture SQL (NutrixDataProvider::charger) donne exactement les mêmes données que
 * les tests unitaires, que chaque méthode de MoteurCalcul renvoie le même résultat, et que les
 * écritures (planning généré, repas journalisé, sorties de stock FEFO) arrivent bien en base.
 *
 * AppFixtures vide les tables avant de les remplir : ce test ne tourne que sur une base dont le nom
 * finit par « _test » (dbname_suffix de l'environnement test), et il est ignoré si elle est injoignable.
 */
class MoteurCalculIntegrationTest extends KernelTestCase
{
    use FixturesNutrixTrait;

    private Connection $connexion;
    private NutrixDataProvider $donnees;
    private MoteurCalcul $moteur;
    private BesoinsCalculator $besoins;
    private RecetteCalculator $recettes;
    private StockCalculator $stock;
    private EcartCalculator $ecarts;
    private PlanningCalculator $planning;
    private PrevisionCalculator $previsions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->connexion = $em->getConnection();

        try {
            $base = (string) $this->connexion->getDatabase();
            $this->connexion->executeQuery('SELECT 1 FROM PLANNING_REPAS LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Base de test injoignable ou non migrée : '.$e->getMessage());
        }
        if (!str_ends_with($base, '_test')) {
            self::markTestSkipped(sprintf('La base « %s » n\'est pas une base de test : fixtures non chargées.', $base));
        }

        (new AppFixtures($container->get(UserPasswordHasherInterface::class)))->load($em);

        $this->besoins = new BesoinsCalculator(new EfsaReference(dirname(__DIR__, 3).'/config/nutrix/efsa_drv_reference.json'));
        $this->recettes = new RecetteCalculator();
        $this->stock = new StockCalculator($this->besoins);
        $this->previsions = new PrevisionCalculator($this->besoins, $this->recettes, $this->stock);
        $this->ecarts = new EcartCalculator($this->besoins, $this->recettes);
        $this->planning = new PlanningCalculator($this->besoins, $this->recettes, $this->stock, $this->ecarts, $this->previsions);
        $this->donnees = new NutrixDataProvider($this->connexion, new MockClock(MoteurTestCase::DATE_REFERENCE));
        $this->moteur = new MoteurCalcul($this->donnees, $this->besoins, $this->recettes, $this->stock, $this->planning, $this->ecarts, $this->previsions);
    }

    public function testLectureEnBaseIdentiqueAuxFixtures(): void
    {
        $enBase = $this->donnees->charger();

        self::assertEquals($this->donneesFixtures(), $enBase);
        self::assertSame(MoteurTestCase::DATE_REFERENCE, $enBase->aujourdhui->format('Y-m-d'), 'date fournie par l\'horloge');
        self::assertFalse($enBase->planningOccupantLie);
        self::assertNull($enBase->alimentAllergenes, 'table ALIMENT_ALLERGENE absente du schéma');
    }

    public function testRoutesEnLectureIdentiquesAuCalculEnMemoire(): void
    {
        $d = $this->donneesFixtures();
        $simulation = ['equipage_ids' => [1, 2], 'repas' => [['date' => '2026-09-23', 'type_repas' => 'Diner', 'recette_id' => 3, 'portions' => 2]]];

        self::assertEquals($this->besoins->besoinEquipier($d, 4, 900.0), $this->moteur->besoinEquipier(4, 900.0));
        self::assertEquals($this->besoins->besoinEquipe($d, $d->aujourdhui), $this->moteur->besoinEquipe());
        self::assertEquals($this->besoins->besoinCreneau($d, new \DateTimeImmutable('2026-09-25'), 'Collation'), $this->moteur->besoinCreneau('2026-09-25', 'Collation'));
        self::assertEquals($this->ecarts->ecart($d, 1, 7), $this->moteur->ecartNutritionnel(1, 7));
        self::assertEquals($this->recettes->profilNutritionnel($d, 4), $this->moteur->profilRecette(4));
        self::assertEquals($this->planning->simuler($d, $simulation), $this->moteur->simulerPlanning($simulation));
        self::assertEquals($this->planning->lire($d, '2026-09-23', null, null), $this->moteur->lirePlanning('2026-09-23', null, null));
        self::assertEquals($this->stock->autonomie($d), $this->moteur->autonomie());
        self::assertEquals($this->previsions->previsions($d, 8), $this->moteur->previsions(8));
        self::assertEquals($this->previsions->besoinsAgricoles($d, 1), $this->moteur->besoinsAgricoles(1));
    }

    public function testGenererEnregistreLePlanning(): void
    {
        $entree = ['equipage_ids' => [2, 3, 4], 'date_debut' => '2026-09-25', 'horizon_jours' => 1, 'graine' => 3, 'autoriser_rupture_stock' => true];

        $apercu = $this->moteur->genererPlanning($entree + ['enregistrer' => false]);
        self::assertSame([], $apercu['planning_repas_ids']);
        self::assertSame(4, $this->compter('PLANNING_REPAS'));

        $r = $this->moteur->genererPlanning($entree);
        self::assertCount(12, $r['planning_repas_ids']); // 4 créneaux × 3 groupes
        self::assertSame(16, $this->compter('PLANNING_REPAS'));
        self::assertFalse($r['occupants_enregistres'], 'PLANNING_REPAS_OCCUPANT sans Id_PLANNING_REPAS');
        self::assertContains('schema', array_column($r['alertes'], 'type'));

        $lignes = $this->connexion->fetchAllAssociative('SELECT date_, type_repas, Id_Recette, portions_prevues FROM PLANNING_REPAS WHERE date_ = ? ORDER BY Id_PLANNING_REPAS', ['2026-09-25']);
        self::assertCount(12, $lignes);
        self::assertSame(['Petit-dejeuner', 'Dejeuner', 'Diner', 'Collation'], array_values(array_unique(array_column($lignes, 'type_repas'))));
        self::assertEquals(1.0, (float) $lignes[0]['portions_prevues']);

        // Relu en base, le planning généré alimente la lecture et le roulement.
        self::assertCount(12, $this->moteur->lirePlanning('2026-09-25', '2026-09-25', null)['planning']);
    }

    public function testRepasJournaliseAvecSortieDeStockFefo(): void
    {
        $r = $this->moteur->enregistrerRepas([
            'equipage_id' => 2, 'recette_id' => 5, 'type_repas_id' => 1, 'date_heure' => '2026-09-23T07:30:00', 'portion_g' => 300, 'sortie_stock' => true,
        ]);

        self::assertSame(540.0, $r['apport']['kcal']); // 300 g × 180 kcal/100 g
        self::assertSame(6, $this->compter('JOURNAL_REPAS'));
        self::assertSame(['equipage_id' => 2, 'recette_id' => 5, 'portion_g' => 300.0], ['equipage_id' => (int) $this->journal($r['id'])['Id_Equipage'], 'recette_id' => (int) $this->journal($r['id'])['Id_Recette'], 'portion_g' => (float) $this->journal($r['id'])['portion_g']]);

        // 300 g de lait sortis du lot 4 (seul lot courant), tracés et reliés à la recette.
        self::assertSame([['lot_id' => 4, 'quantite_g' => 300.0, 'date_peremption' => '2027-01-15']], $r['sorties_stock'][0]['sorties']);
        self::assertEquals(11700.0, (float) $this->connexion->fetchOne('SELECT quantite_disponible_g FROM LOT_STOCK WHERE Id_LOT_STOCK = 4'));
        $mouvement = $this->connexion->fetchAssociative('SELECT * FROM MOUVEMENT_STOCK ORDER BY Id_MOUVEMENT_STOCK DESC LIMIT 1');
        self::assertSame(['Sortie', 300.0, 4], [$mouvement['type_mouvement'], (float) $mouvement['quantite_g'], (int) $mouvement['Id_LOT_STOCK']]);
        self::assertSame(1, (int) $this->connexion->fetchOne('SELECT COUNT(*) FROM Asso_11 WHERE Id_Recette = 5 AND Id_MOUVEMENT_STOCK = ?', [$mouvement['Id_MOUVEMENT_STOCK']]));

        // Le stock relu tient compte de la sortie.
        self::assertSame(['LAIT_POUDRE' => 11700.0], $this->stock->disponible($this->donnees->charger(), new \DateTimeImmutable(MoteurTestCase::DATE_REFERENCE)));
    }

    public function testSortieImpossibleSansStockNeCreeAucunMouvement(): void
    {
        $mouvements = $this->compter('MOUVEMENT_STOCK');
        $r = $this->moteur->enregistrerRepas([
            'equipage_id' => 2, 'recette_id' => 3, 'type_repas_id' => 3, 'date_heure' => '2026-09-23T19:00:00', 'portion_g' => 200, 'sortie_stock' => true,
        ]);

        self::assertSame(200.0, $r['sorties_stock'][0]['manque_g'], 'aucune pomme de terre en réserve courante');
        self::assertSame($mouvements, $this->compter('MOUVEMENT_STOCK'));
    }

    public function testEntreeInvalideNEcritRien(): void
    {
        try {
            $this->moteur->enregistrerRepas(['equipage_id' => 2, 'recette_id' => 99, 'type_repas_id' => 1]);
            self::fail('422 attendu');
        } catch (MoteurException $e) {
            self::assertSame(422, $e->getStatutHttp());
        }
        self::assertSame(5, $this->compter('JOURNAL_REPAS'));
    }

    private function compter(string $table): int
    {
        return (int) $this->connexion->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    /** @return array<string, mixed> */
    private function journal(int $id): array
    {
        return $this->connexion->fetchAssociative('SELECT * FROM JOURNAL_REPAS WHERE Id_JOURNAL_REPAS = ?', [$id]);
    }
}
