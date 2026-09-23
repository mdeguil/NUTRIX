<?php

namespace App\Tests\Moteur;

use App\DataFixtures\AppFixtures;
use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Donnees\NutrixDataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données de AppFixtures sans base de données.
 *
 * AppFixtures::load() est réellement exécuté, mais contre une connexion simulée qui enregistre
 * chaque insert() : on récupère les lignes exactes des fixtures, que NutrixDataProvider::construire()
 * transforme comme le ferait une lecture en base.
 */
trait FixturesNutrixTrait
{
    /** @var array<string, list<array<string, mixed>>>|null lignes insérées par AppFixtures, par table */
    private static ?array $lignesFixtures = null;

    /** Données du vaisseau telles que chargées depuis une base remplie par AppFixtures. */
    protected function donneesFixtures(string $aujourdhui = MoteurTestCase::DATE_REFERENCE): DonneesVaisseau
    {
        // PLANNING_REPAS_OCCUPANT porte Id_PLANNING_REPAS depuis la migration Version20260923090000 (gap #10 corrige).
        return NutrixDataProvider::construire($this->lignesFixtures(), new \DateTimeImmutable($aujourdhui), true);
    }

    /** @return array<string, list<array<string, mixed>>> */
    protected function lignesFixtures(): array
    {
        if (null !== self::$lignesFixtures) {
            return self::$lignesFixtures;
        }

        $lignes = [];
        $connexion = $this->createMock(Connection::class);
        $connexion->method('insert')->willReturnCallback(static function (string $table, array $ligne) use (&$lignes): int {
            $lignes[$table][] = $ligne;

            return 1;
        });

        $utilisateurs = $this->createMock(EntityRepository::class);
        $utilisateurs->method('findAll')->willReturn([]);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connexion);
        $manager->method('getRepository')->willReturn($utilisateurs);

        $hasheur = $this->createMock(UserPasswordHasherInterface::class);
        $hasheur->method('hashPassword')->willReturn('hash');

        (new AppFixtures($hasheur))->load($manager);

        return self::$lignesFixtures = $lignes;
    }
}
