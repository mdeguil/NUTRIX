<?php

namespace App\Tests\Controller;

use App\DataFixtures\AppFixtures;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Requêtes du front (Docs/API_REQUETES_FRONT.md) sur une base remplie par AppFixtures :
 * format des réponses, droits par rôle et format d'erreur commun.
 *
 * Comme MoteurCalculIntegrationTest, ne tourne que sur une base « _test » joignable.
 */
class FrontControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        try {
            $base = (string) $em->getConnection()->getDatabase();
            $em->getConnection()->executeQuery('SELECT 1 FROM PLANNING_REPAS LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Base de test injoignable ou non migrée : '.$e->getMessage());
        }
        if (!str_ends_with($base, '_test')) {
            self::markTestSkipped(sprintf('La base « %s » n\'est pas une base de test.', $base));
        }
        (new AppFixtures($container->get(UserPasswordHasherInterface::class)))->load($em);
        $em->clear();
    }

    public function testMeRenvoieLEquipageLie(): void
    {
        $me = $this->get('/api/me', 'occupant');
        self::assertSame(1, $me['equipageId']);
        self::assertSame(['Martin', 'Lucas'], [$me['nom'], $me['prenom']]);

        self::assertNull($this->get('/api/me', 'ferme')['equipageId']);
    }

    public function testOccupants(): void
    {
        $occupants = $this->get('/api/occupants?date=2026-09-20', 'occupant');

        self::assertCount(4, $occupants);
        $lucas = $occupants[0];
        self::assertEquals(['id' => 1, 'nom' => 'Martin', 'prenom' => 'Lucas', 'sexe' => 'Homme', 'fonction' => 'Commandant de bord', 'niveauActivite' => 'Moderee'],
            array_intersect_key($lucas, array_flip(['id', 'nom', 'prenom', 'sexe', 'fonction', 'niveauActivite'])));
        self::assertSame([['id' => 2, 'nom' => 'Lactose']], $lucas['allergenes']);
        // 20/09 : bouillie lactée 250 g à 180 kcal/100 g + riz aux haricots 400 g à 128 kcal/100 g.
        self::assertSame(962, $lucas['apportsActuelsKcal']);
        self::assertIsInt($lucas['apportsKcal']);
        self::assertSame('Femme', $occupants[1]['sexe']);

        $this->get('/api/occupants', 'ferme', 403);
        self::assertSame(['error' => 'access_denied', 'message' => 'Accès refusé'], $this->json(), 'les règles de sécurité ne sont pas divulguées');
    }

    public function testDetailOccupantLimiteASoiPourUnOccupant(): void
    {
        $detail = $this->get('/api/occupants/1', 'occupant');
        self::assertArrayHasKey('energy_kcal', $detail['besoins']);

        $this->get('/api/occupants/2', 'occupant', 403);
        $this->get('/api/occupants/2', 'admin');
        $this->get('/api/occupants/99', 'admin', 404);
        self::assertSame('not_found', $this->json()['error']);
    }

    public function testBilanJournalier(): void
    {
        $bilan = $this->get('/api/me/bilan-journalier?date=2026-09-20', 'occupant');

        self::assertSame(1, $bilan['equipageId']);
        self::assertSame(['consomme' => 962, 'objectif' => $bilan['calories']['objectif'], 'unite' => 'kcal'], $bilan['calories']);
        self::assertGreaterThan(2000, $bilan['calories']['objectif']);
        self::assertEqualsWithDelta(($bilan['glucides']['objectifMin'] + $bilan['glucides']['objectifMax']) / 2, $bilan['glucides']['objectif'], 0.1);
        self::assertNull($bilan['eau']['consomme']);

        $this->get('/api/me/bilan-journalier?equipageId=2', 'occupant', 403);
        self::assertNull($this->get('/api/me/bilan-journalier', 'admin')['equipageId'], 'admin sans equipageId : total équipage');
        self::assertSame(2, $this->get('/api/me/bilan-journalier?equipageId=2', 'admin')['equipageId']);
        $this->get('/api/me/bilan-journalier?date=20-09-2026', 'occupant', 422);
        self::assertSame('date', $this->json()['violations'][0]['field']);
    }

    public function testReferentiels(): void
    {
        self::assertSame(['id' => 1, 'libelle' => 'Petit-dejeuner'], $this->get('/api/types-repas', 'ferme')[0]);
        self::assertSame([['id' => 1, 'nom' => 'Gluten'], ['id' => 2, 'nom' => 'Lactose']], array_slice($this->get('/api/allergenes', 'ferme'), 0, 2));
    }

    public function testRecettesPlanifiees(): void
    {
        $planifiees = $this->get('/api/recettes/planifiees?dateDebut=2026-09-23&dateFin=2026-09-24', 'occupant');

        self::assertCount(4, $planifiees);
        self::assertSame(['recetteId' => 5, 'libelle' => 'Bouillie lactee', 'date' => '2026-09-23', 'typeRepas' => ['id' => 1, 'libelle' => 'Petit-dejeuner'], 'poidsPortionG' => 350, 'kcalPortion' => 630], $planifiees[0]);
        self::assertCount(1, $this->get('/api/recettes/planifiees?dateDebut=2026-09-23&dateFin=2026-09-24&typeRepasId=3', 'occupant'));
    }

    public function testJournalLectureEtEnregistrement(): void
    {
        $journal = $this->get('/api/journal-repas', 'occupant');
        self::assertSame(2, $journal['totalItems'], 'un occupant ne voit que ses repas');
        self::assertSame(['id' => 2, 'dateHeure' => (new \DateTimeImmutable('2026-09-20 12:15:00'))->format(\DATE_ATOM)], array_slice($journal['items'][0], 0, 2));
        self::assertSame('Portion complete', $journal['items'][0]['notes']);
        self::assertSame(['kcal' => 512, 'proteinesG' => 22, 'glucidesG' => 100, 'lipidesG' => 1.6, 'fibresG' => 14], $journal['items'][0]['apport']);
        self::assertSame(5, $this->get('/api/journal-repas?itemsPerPage=2', 'admin')['totalItems']);
        self::assertCount(2, $this->get('/api/journal-repas?itemsPerPage=2', 'admin')['items']);
        $this->get('/api/journal-repas?equipageId=2', 'occupant', 403);

        $cree = $this->post('/api/journal-repas', 'occupant', [
            'equipageId' => 1, 'recetteId' => 5, 'typeRepasId' => 1,
            'dateHeure' => '2026-09-22T08:00:00+02:00', 'portionG' => 200, 'notes' => 'Petit appétit',
        ], 201);
        self::assertSame(['id' => 1, 'nom' => 'Martin', 'prenom' => 'Lucas'], $cree['equipage']);
        self::assertSame('Petit appétit', $cree['notes']);
        self::assertSame(360, $cree['apport']['kcal']);
        self::assertSame((new \DateTimeImmutable('2026-09-22T08:00:00+02:00'))->getTimestamp(), (new \DateTimeImmutable($cree['dateHeure']))->getTimestamp());
        self::assertSame($cree, $this->get('/api/journal-repas', 'occupant')['items'][0], 'R11 renvoie le même objet que R10');

        // Sortie de stock FEFO : la bouillie lactée ne contient que du lait en poudre (lot 4, 12 000 g).
        $lait = $this->get('/api/stock/categories', 'admin');
        $items = array_merge(...array_column($lait, 'items'));
        self::assertSame(11800, $items[array_search('LAIT_POUDRE', array_column($items, 'alimentId'), true)]['quantite']);
    }

    public function testJournalRefuseLesEntreesInvalides(): void
    {
        $this->post('/api/journal-repas', 'occupant', ['dateHeure' => '2999-01-01T12:00:00+00:00', 'portionG' => -1], 422);
        $erreur = $this->json();
        self::assertSame('validation_failed', $erreur['error']);
        self::assertEqualsCanonicalizing(['equipageId', 'recetteId', 'typeRepasId', 'dateHeure', 'portionG'], array_column($erreur['violations'], 'field'));

        $this->post('/api/journal-repas', 'occupant', ['equipageId' => 2, 'recetteId' => 5, 'typeRepasId' => 1, 'dateHeure' => '2026-09-22T08:00:00+02:00'], 403);
        $this->post('/api/journal-repas', 'occupant', ['equipageId' => 1, 'recetteId' => 99, 'typeRepasId' => 1, 'dateHeure' => '2026-09-22T08:00:00+02:00'], 404);
        $this->post('/api/journal-repas', 'ferme', ['equipageId' => 1, 'recetteId' => 5, 'typeRepasId' => 1, 'dateHeure' => '2026-09-22T08:00:00+02:00'], 403);
    }

    public function testStockParCategorie(): void
    {
        $categories = $this->get('/api/stock/categories', 'ferme');
        $parCode = array_column($categories, null, 'code');

        self::assertSame(['LAIT_POUDRE'], array_column($parCode['produitlaitier']['items'], 'alimentId'));
        $lait = $parCode['produitlaitier']['items'][0];
        self::assertSame(['quantite' => 12000, 'unite' => 'g', 'quantiteAffichee' => '12 kg', 'prochainePeremption' => '2027-01-15'],
            array_intersect_key($lait, array_flip(['quantite', 'unite', 'quantiteAffichee', 'prochainePeremption'])));
        // Réserve de sécurité : comptée dans l'inventaire, pas dans le stock consommable.
        $haricot = $parCode['proteine']['items'][array_search('HARICOT_ROUGE', array_column($parCode['proteine']['items'], 'alimentId'), true)];
        self::assertSame([3100, 0], [$haricot['quantite'], $haricot['quantiteConsommable']]);
        self::assertArrayNotHasKey('legume', $parCode, 'lot de tomates périmé : rien en stock');
        foreach ($categories as $c) {
            self::assertThat($c['niveauPercent'], self::logicalAnd(self::greaterThanOrEqual(0), self::lessThanOrEqual(100)));
        }
    }

    public function testPrevisionnelEtStatistiques(): void
    {
        $prevision = $this->get('/api/previsions/production?semaines=4', 'ferme');
        self::assertSame(4, $prevision['horizonSemaines']);
        foreach ($prevision['aliments'] as $a) {
            self::assertEqualsWithDelta($a['consommationHebdoG'] * 4, $a['besoinTotalG'], 2);
            self::assertEqualsWithDelta(max(0, $a['besoinTotalG'] - $a['stockActuelG'] - $a['recoltesPrevuesG']), $a['aProduireG'], 2);
            self::assertContains($a['priorite'], ['urgente', 'moyenne', 'faible']);
        }
        $this->get('/api/previsions/production', 'occupant', 403);
        $this->get('/api/previsions/production?semaines=0', 'admin', 422);

        $periode = '?periode=personnalisee&dateDebut=2026-09-01&dateFin=2026-09-30';
        $menus = $this->get('/api/statistiques/menus-servis'.$periode, 'ferme');
        self::assertSame(['recetteId' => 3, 'nom' => 'Puree de pomme de terre', 'fois' => 2], $menus[0]);
        self::assertSame(5, array_sum(array_column($menus, 'fois')));

        $aliments = $this->get('/api/statistiques/aliments-consommes'.$periode.'&limit=2', 'ferme');
        self::assertCount(2, $aliments);
        self::assertGreaterThanOrEqual($aliments[1]['quantiteG'], $aliments[0]['quantiteG']);
        // Purée 300 g + 200 g, composée uniquement de pommes de terre.
        $tous = array_column($this->get('/api/statistiques/aliments-consommes'.$periode, 'ferme'), 'quantiteG', 'alimentId');
        self::assertEquals(500, $tous['POMME_TERRE']);

        $this->get('/api/statistiques/menus-servis?periode=personnalisee', 'ferme', 422);
        $this->get('/api/statistiques/menus-servis?periode=annee', 'ferme', 422);
    }

    public function testCatalogueDesRecettes(): void
    {
        $recettes = array_column($this->get('/api/recettes', 'ferme'), null, 'id');

        self::assertCount(5, $recettes);
        $bouillie = $recettes[5];
        self::assertSame([630, 31.5, 350], [$bouillie['calories'], $bouillie['proteines'], $bouillie['poidsPortionG']]);
        self::assertSame([['id' => 'LAIT_POUDRE', 'nom' => 'Lait en poudre', 'quantiteG' => 350]], $bouillie['aliments']);
        self::assertSame([['id' => 2, 'nom' => 'Lactose']], $bouillie['allergenes']);
        self::assertSame([34, true], [$bouillie['portionsRealisables'], $bouillie['disponible']]); // 12 000 g / 350 g
        self::assertFalse($recettes[3]['disponible'], 'pas de pommes de terre en stock');

        self::assertSame([1, 3, 4], array_column($this->get('/api/recettes?sansAllergenes[]=2', 'ferme'), 'id'));
        self::assertSame([1, 3, 4], array_column($this->get('/api/recettes?sansAllergenes=2', 'ferme'), 'id'));
        self::assertSame([5], array_column($this->get('/api/recettes?disponible=true', 'ferme'), 'id'));
        self::assertSame([], $this->get('/api/recettes?disponible=true&portions=100', 'ferme'));
    }

    public function testAgricultureEtStatut(): void
    {
        $agriculture = $this->get('/api/agriculture/besoins-plantation', 'ferme');
        self::assertSame(45, $agriculture['autonomieCibleJours']);
        $riz = array_column($agriculture['aliments'], null, 'alimentId')['RIZ'];
        self::assertSame([['id' => 2, 'moduleCulture' => 'Module B2', 'dateRecoltePrevue' => '2026-10-01', 'quantitePrevueG' => 8000]], $riz['recoltesEnCours']);
        self::assertSame([100, 120], [$riz['cycleJoursMin'], $riz['cycleJoursMax']]);
        $this->get('/api/agriculture/besoins-plantation', 'occupant', 403);

        $statut = $this->get('/api/status', 'occupant');
        self::assertSame(['ok', 'ok'], [$statut['api'], $statut['database']]);
        self::assertContains($statut['niveau'], ['nominal', 'attention', 'critique']);
    }

    public function testErreursAuFormatCommun(): void
    {
        $this->get('/api/route-inexistante', 'admin', 404);
        self::assertSame('not_found', $this->json()['error']);
        self::assertArrayHasKey('message', $this->json());

        $this->client->request('GET', '/api/occupants');
        self::assertResponseStatusCodeSame(401);
    }

    public function testInscriptionAvecProfil(): void
    {
        $username = 'nouvel_'.uniqid();
        $this->client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => $username, 'password' => 'password123', 'role' => 'ROLE_OCCUPANT', 'nom' => 'Petit', 'prenom' => 'Jeanne',
            'profil' => ['sexe' => 'Femme', 'age' => 31, 'poidsKg' => 60, 'tailleCm' => 165, 'pal' => 1.6, 'activiteId' => 3],
        ]));
        self::assertResponseStatusCodeSame(201);
        $equipageId = $this->json()['equipageId'];
        self::assertIsInt($equipageId);

        $me = $this->get('/api/me', $username);
        self::assertSame([$equipageId, 'Petit', 'Jeanne'], [$me['equipageId'], $me['nom'], $me['prenom']]);
        self::assertCount(5, $this->get('/api/occupants', $username));

        $this->client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => 'x_'.uniqid(), 'password' => 'court', 'profil' => ['sexe' => 'X'],
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertContains('password', array_column($this->json()['violations'], 'field'));
        self::assertContains('profil.sexe', array_column($this->json()['violations'], 'field'));
    }

    private function get(string $url, string $utilisateur, int $statut = 200): array
    {
        $this->client->request('GET', $url, server: $this->entetes($utilisateur));
        self::assertResponseStatusCodeSame($statut, $this->client->getResponse()->getContent());

        return $this->json();
    }

    private function post(string $url, string $utilisateur, array $corps, int $statut): array
    {
        $this->client->request('POST', $url, server: $this->entetes($utilisateur) + ['CONTENT_TYPE' => 'application/json'], content: json_encode($corps));
        self::assertResponseStatusCodeSame($statut, $this->client->getResponse()->getContent());

        return $this->json();
    }

    private function json(): array
    {
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /** Jeton JWT d'un compte des fixtures (admin, occupant, ferme) ou d'un compte créé par le test. */
    private function entetes(string $utilisateur): array
    {
        $container = static::getContainer();
        $user = $container->get('doctrine.orm.entity_manager')->getRepository(User::class)->findOneBy(['username' => $utilisateur]);
        self::assertNotNull($user, "compte $utilisateur introuvable");

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$container->get(JWTTokenManagerInterface::class)->create($user), 'HTTP_ACCEPT' => 'application/json'];
    }
}
