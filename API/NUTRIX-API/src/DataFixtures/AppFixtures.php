<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Connection $connection */
        $connection = $manager->getConnection();

        $this->loadUsers($manager);

        $this->clear($connection);

        $this->insertAll($connection, 'unite_stock', [
            ['Id_unite_stock' => 1, 'Libelle' => 'g'],
            ['Id_unite_stock' => 2, 'Libelle' => 'kg'],
            ['Id_unite_stock' => 3, 'Libelle' => 'L'],
            ['Id_unite_stock' => 4, 'Libelle' => 'mL'],
            ['Id_unite_stock' => 5, 'Libelle' => 'unite'],
        ]);

        $this->insertAll($connection, 'Categorie_ingredient', [
            ['Id_Categorie_ingredient' => 1, 'Libelle' => 'Legume'],
            ['Id_Categorie_ingredient' => 2, 'Libelle' => 'Fruit'],
            ['Id_Categorie_ingredient' => 3, 'Libelle' => 'Cereale'],
            ['Id_Categorie_ingredient' => 4, 'Libelle' => 'Proteine'],
            ['Id_Categorie_ingredient' => 5, 'Libelle' => 'Produit laitier'],
        ]);

        $this->insertAll($connection, 'categorie_recette', [
            ['Id_categorie_recette' => 1, 'libelle' => 'Petit-dejeuner'],
            ['Id_categorie_recette' => 2, 'libelle' => 'Dejeuner'],
            ['Id_categorie_recette' => 3, 'libelle' => 'Diner'],
            ['Id_categorie_recette' => 4, 'libelle' => 'Collation'],
        ]);

        $this->insertAll($connection, 'Activity_label', [
            ['Id_Activity_label' => 1, 'libelle' => 'Sedentaire'],
            ['Id_Activity_label' => 2, 'libelle' => 'Legere'],
            ['Id_Activity_label' => 3, 'libelle' => 'Moderee'],
            ['Id_Activity_label' => 4, 'libelle' => 'Intense'],
        ]);

        $this->insertAll($connection, 'ALLERGENE', [
            ['Id_ALLERGENE' => 1, 'libelle' => 'Gluten'],
            ['Id_ALLERGENE' => 2, 'libelle' => 'Lactose'],
            ['Id_ALLERGENE' => 3, 'libelle' => 'Arachide'],
            ['Id_ALLERGENE' => 4, 'libelle' => 'Fruits a coque'],
            ['Id_ALLERGENE' => 5, 'libelle' => 'Soja'],
        ]);

        $this->insertAll($connection, 'type_repas', [
            ['Id_type_repas' => 1, 'libelle' => 'Petit-dejeuner'],
            ['Id_type_repas' => 2, 'libelle' => 'Dejeuner'],
            ['Id_type_repas' => 3, 'libelle' => 'Diner'],
            ['Id_type_repas' => 4, 'libelle' => 'Collation'],
        ]);

        $this->insertAll($connection, 'Aliment', [
            ['Id_Aliment' => 'TOMATE', 'Libelle' => 'Tomate', 'Kcal_100g' => 18.00, 'Proteines_100g' => 0.90, 'Glucides_100g' => 3.90, 'Lipides_100g' => 0.20, 'Fibres_100g' => 1.20, 'Cycle_jours_min' => 60, 'rendement_g_m2_j' => 4.50, 'partie_replantable' => 'Tige', 'Cycle_jours_max' => 80, 'Id_unite_stock' => 1, 'Id_Categorie_ingredient' => 1],
            ['Id_Aliment' => 'RIZ', 'Libelle' => 'Riz', 'Kcal_100g' => 130.00, 'Proteines_100g' => 2.70, 'Glucides_100g' => 28.00, 'Lipides_100g' => 0.30, 'Fibres_100g' => 0.40, 'Cycle_jours_min' => 100, 'rendement_g_m2_j' => 2.00, 'partie_replantable' => null, 'Cycle_jours_max' => 120, 'Id_unite_stock' => 2, 'Id_Categorie_ingredient' => 3],
            ['Id_Aliment' => 'HARICOT_ROUGE', 'Libelle' => 'Haricot rouge', 'Kcal_100g' => 127.00, 'Proteines_100g' => 8.70, 'Glucides_100g' => 22.80, 'Lipides_100g' => 0.50, 'Fibres_100g' => 6.40, 'Cycle_jours_min' => 70, 'rendement_g_m2_j' => 1.80, 'partie_replantable' => null, 'Cycle_jours_max' => 90, 'Id_unite_stock' => 2, 'Id_Categorie_ingredient' => 4],
            ['Id_Aliment' => 'POMME_TERRE', 'Libelle' => 'Pomme de terre', 'Kcal_100g' => 77.00, 'Proteines_100g' => 2.00, 'Glucides_100g' => 17.00, 'Lipides_100g' => 0.10, 'Fibres_100g' => 2.20, 'Cycle_jours_min' => 70, 'rendement_g_m2_j' => 3.50, 'partie_replantable' => 'Tubercule', 'Cycle_jours_max' => 100, 'Id_unite_stock' => 2, 'Id_Categorie_ingredient' => 1],
            ['Id_Aliment' => 'OEUF_POUDRE', 'Libelle' => 'Oeuf en poudre', 'Kcal_100g' => 578.00, 'Proteines_100g' => 47.00, 'Glucides_100g' => 4.00, 'Lipides_100g' => 41.00, 'Fibres_100g' => 0.00, 'Cycle_jours_min' => 0, 'rendement_g_m2_j' => null, 'partie_replantable' => null, 'Cycle_jours_max' => 0, 'Id_unite_stock' => 2, 'Id_Categorie_ingredient' => 4],
            ['Id_Aliment' => 'LAIT_POUDRE', 'Libelle' => 'Lait en poudre', 'Kcal_100g' => 496.00, 'Proteines_100g' => 26.00, 'Glucides_100g' => 38.00, 'Lipides_100g' => 26.00, 'Fibres_100g' => 0.00, 'Cycle_jours_min' => 0, 'rendement_g_m2_j' => null, 'partie_replantable' => null, 'Cycle_jours_max' => 0, 'Id_unite_stock' => 2, 'Id_Categorie_ingredient' => 5],
            ['Id_Aliment' => 'SALADE', 'Libelle' => 'Salade verte', 'Kcal_100g' => 15.00, 'Proteines_100g' => 1.40, 'Glucides_100g' => 2.90, 'Lipides_100g' => 0.20, 'Fibres_100g' => 1.30, 'Cycle_jours_min' => 30, 'rendement_g_m2_j' => 3.00, 'partie_replantable' => 'Feuille', 'Cycle_jours_max' => 45, 'Id_unite_stock' => 1, 'Id_Categorie_ingredient' => 1],
        ]);

        $this->insertAll($connection, 'Recette', [
            ['Id_Recette' => 1, 'libelle' => 'Riz aux haricots rouges', 'proteines_g_100g' => '5.5', 'glucides_g_100g' => '25.0', 'lipides_g_100g' => '0.4', 'fibres_g_100g' => '3.5', 'poids_total_g' => 450.00, 'kcal_100g' => '128', 'temps_preparation' => '25 min', 'cycle_production' => 1, 'Id_categorie_recette' => 2],
            ['Id_Recette' => 2, 'libelle' => 'Omelette reconstituee', 'proteines_g_100g' => '20.0', 'glucides_g_100g' => '2.0', 'lipides_g_100g' => '15.0', 'fibres_g_100g' => '0.0', 'poids_total_g' => 200.00, 'kcal_100g' => '220', 'temps_preparation' => '10 min', 'cycle_production' => 1, 'Id_categorie_recette' => 1],
            ['Id_Recette' => 3, 'libelle' => 'Puree de pomme de terre', 'proteines_g_100g' => '2.0', 'glucides_g_100g' => '17.0', 'lipides_g_100g' => '0.3', 'fibres_g_100g' => '2.2', 'poids_total_g' => 300.00, 'kcal_100g' => '80', 'temps_preparation' => '20 min', 'cycle_production' => 1, 'Id_categorie_recette' => 3],
            ['Id_Recette' => 4, 'libelle' => 'Salade de tomates', 'proteines_g_100g' => '1.0', 'glucides_g_100g' => '3.5', 'lipides_g_100g' => '0.2', 'fibres_g_100g' => '1.3', 'poids_total_g' => 250.00, 'kcal_100g' => '17', 'temps_preparation' => '10 min', 'cycle_production' => 1, 'Id_categorie_recette' => 4],
            ['Id_Recette' => 5, 'libelle' => 'Bouillie lactee', 'proteines_g_100g' => '9.0', 'glucides_g_100g' => '18.0', 'lipides_g_100g' => '9.0', 'fibres_g_100g' => '0.0', 'poids_total_g' => 350.00, 'kcal_100g' => '180', 'temps_preparation' => '15 min', 'cycle_production' => 1, 'Id_categorie_recette' => 1],
        ]);

        $this->insertAll($connection, 'RECOLTE', [
            ['Id_RECOLTE' => 1, 'module_culture' => 'Module A1', 'date_semis' => '2026-06-01', 'date_recolte_prevue' => '2026-08-01', 'date_recolte_reelle' => '2026-08-02', 'quantite_prevue_g' => 5000.00, 'quantite_reelle_g' => 4700.00, 'statut' => 'Recoltee', 'taux_perte_pct' => 6.00, 'Id_Aliment' => 'TOMATE'],
            ['Id_RECOLTE' => 2, 'module_culture' => 'Module B2', 'date_semis' => '2026-07-01', 'date_recolte_prevue' => '2026-10-01', 'date_recolte_reelle' => null, 'quantite_prevue_g' => 8000.00, 'quantite_reelle_g' => null, 'statut' => 'Planifiee', 'taux_perte_pct' => null, 'Id_Aliment' => 'RIZ'],
            ['Id_RECOLTE' => 3, 'module_culture' => 'Module A2', 'date_semis' => '2026-06-15', 'date_recolte_prevue' => '2026-08-25', 'date_recolte_reelle' => '2026-08-24', 'quantite_prevue_g' => 3000.00, 'quantite_reelle_g' => 3100.00, 'statut' => 'Recoltee', 'taux_perte_pct' => 0.00, 'Id_Aliment' => 'HARICOT_ROUGE'],
            ['Id_RECOLTE' => 4, 'module_culture' => 'Module C1', 'date_semis' => '2026-07-10', 'date_recolte_prevue' => '2026-09-20', 'date_recolte_reelle' => null, 'quantite_prevue_g' => 4500.00, 'quantite_reelle_g' => null, 'statut' => 'En cours', 'taux_perte_pct' => null, 'Id_Aliment' => 'SALADE'],
        ]);

        $this->insertAll($connection, 'LOT_STOCK', [
            ['Id_LOT_STOCK' => 1, 'qr_code' => 'QR-TOM-0001', 'quantite_initiale_g' => 4700.00, 'quantite_disponible_g' => 3200.00, 'date_entree' => '2026-08-02', 'date_peremption' => '2026-08-20', 'emplacement' => 'Reserve A - Etagere 1', 'type_reserve' => 'Courante', 'statut' => 'Disponible', 'Id_Aliment' => 'TOMATE', 'Id_RECOLTE' => 1],
            ['Id_LOT_STOCK' => 2, 'qr_code' => 'QR-HAR-0001', 'quantite_initiale_g' => 3100.00, 'quantite_disponible_g' => 3100.00, 'date_entree' => '2026-08-24', 'date_peremption' => '2027-08-24', 'emplacement' => 'Reserve B - Etagere 2', 'type_reserve' => 'Securite', 'statut' => 'Disponible', 'Id_Aliment' => 'HARICOT_ROUGE', 'Id_RECOLTE' => 3],
            ['Id_LOT_STOCK' => 3, 'qr_code' => 'QR-OEU-0001', 'quantite_initiale_g' => 10000.00, 'quantite_disponible_g' => 8500.00, 'date_entree' => '2026-01-15', 'date_peremption' => '2027-01-15', 'emplacement' => 'Reserve C - Etagere 1', 'type_reserve' => 'Urgence', 'statut' => 'Disponible', 'Id_Aliment' => 'OEUF_POUDRE', 'Id_RECOLTE' => null],
            ['Id_LOT_STOCK' => 4, 'qr_code' => 'QR-LAI-0001', 'quantite_initiale_g' => 15000.00, 'quantite_disponible_g' => 12000.00, 'date_entree' => '2026-01-15', 'date_peremption' => '2027-01-15', 'emplacement' => 'Reserve C - Etagere 2', 'type_reserve' => 'Courante', 'statut' => 'Disponible', 'Id_Aliment' => 'LAIT_POUDRE', 'Id_RECOLTE' => null],
        ]);

        $this->insertAll($connection, 'MOUVEMENT_STOCK', [
            ['Id_MOUVEMENT_STOCK' => 1, 'type_mouvement' => 'Entree', 'quantite_g' => 4700.00, 'date_mouvement' => '2026-08-02 09:00:00', 'Id_LOT_STOCK' => 1],
            ['Id_MOUVEMENT_STOCK' => 2, 'type_mouvement' => 'Sortie', 'quantite_g' => 1500.00, 'date_mouvement' => '2026-08-05 12:30:00', 'Id_LOT_STOCK' => 1],
            ['Id_MOUVEMENT_STOCK' => 3, 'type_mouvement' => 'Entree', 'quantite_g' => 3100.00, 'date_mouvement' => '2026-08-24 10:00:00', 'Id_LOT_STOCK' => 2],
            ['Id_MOUVEMENT_STOCK' => 4, 'type_mouvement' => 'Sortie', 'quantite_g' => 2500.00, 'date_mouvement' => '2026-01-20 08:00:00', 'Id_LOT_STOCK' => 3],
        ]);

        $this->insertAll($connection, 'Equipage', [
            ['Id_Equipage' => 1, 'sexe' => 1, 'age' => 34, 'poids_kilo' => 78.50, 'taille_cm' => 180, 'bmi' => 24.20, 'pal' => 1.6, 'Id_Activity_label' => 3],
            ['Id_Equipage' => 2, 'sexe' => 0, 'age' => 29, 'poids_kilo' => 62.00, 'taille_cm' => 165, 'bmi' => 22.80, 'pal' => 1.4, 'Id_Activity_label' => 2],
            ['Id_Equipage' => 3, 'sexe' => 1, 'age' => 45, 'poids_kilo' => 82.00, 'taille_cm' => 176, 'bmi' => 26.50, 'pal' => 1.2, 'Id_Activity_label' => 1],
            ['Id_Equipage' => 4, 'sexe' => 0, 'age' => 27, 'poids_kilo' => 58.50, 'taille_cm' => 170, 'bmi' => 20.20, 'pal' => 1.9, 'Id_Activity_label' => 4],
        ]);

        $this->insertAll($connection, 'JOURNAL_REPAS', [
            ['Id_JOURNAL_REPAS' => 1, 'date_heure' => '2026-09-20 07:30:00', 'portion_g' => 250.00, 'Id_Recette' => 5, 'Id_Equipage' => 1, 'Id_type_repas' => 1],
            ['Id_JOURNAL_REPAS' => 2, 'date_heure' => '2026-09-20 12:15:00', 'portion_g' => 400.00, 'Id_Recette' => 1, 'Id_Equipage' => 1, 'Id_type_repas' => 2],
            ['Id_JOURNAL_REPAS' => 3, 'date_heure' => '2026-09-20 19:00:00', 'portion_g' => 300.00, 'Id_Recette' => 3, 'Id_Equipage' => 2, 'Id_type_repas' => 3],
            ['Id_JOURNAL_REPAS' => 4, 'date_heure' => '2026-09-21 07:45:00', 'portion_g' => 200.00, 'Id_Recette' => 3, 'Id_Equipage' => 3, 'Id_type_repas' => 1],
            ['Id_JOURNAL_REPAS' => 5, 'date_heure' => '2026-09-21 12:30:00', 'portion_g' => 250.00, 'Id_Recette' => 4, 'Id_Equipage' => 4, 'Id_type_repas' => 2],
        ]);

        $this->insertAll($connection, 'PLANNING_REPAS', [
            ['Id_PLANNING_REPAS' => 1, 'date_' => '2026-09-23', 'portions_prevues' => 4.00, 'type_repas' => 'Petit-dejeuner', 'Id_Recette' => 5],
            ['Id_PLANNING_REPAS' => 2, 'date_' => '2026-09-23', 'portions_prevues' => 4.00, 'type_repas' => 'Dejeuner', 'Id_Recette' => 1],
            ['Id_PLANNING_REPAS' => 3, 'date_' => '2026-09-23', 'portions_prevues' => 4.00, 'type_repas' => 'Diner', 'Id_Recette' => 3],
            ['Id_PLANNING_REPAS' => 4, 'date_' => '2026-09-24', 'portions_prevues' => 4.00, 'type_repas' => 'Dejeuner', 'Id_Recette' => 2],
        ]);

        $this->insertAll($connection, 'PLANNING_REPAS_OCCUPANT', [
            ['Id_PLANNING_REPAS_OCCUPANT' => 1, 'portion_ratio' => 1.00, 'Id_Equipage' => 1],
            ['Id_PLANNING_REPAS_OCCUPANT' => 2, 'portion_ratio' => 1.00, 'Id_Equipage' => 2],
            ['Id_PLANNING_REPAS_OCCUPANT' => 3, 'portion_ratio' => 0.80, 'Id_Equipage' => 3],
            ['Id_PLANNING_REPAS_OCCUPANT' => 4, 'portion_ratio' => 1.20, 'Id_Equipage' => 4],
        ]);

        $this->insertAll($connection, 'recette_ingredient', [
            ['Id_Aliment' => 'RIZ', 'Id_Recette' => 1],
            ['Id_Aliment' => 'HARICOT_ROUGE', 'Id_Recette' => 1],
            ['Id_Aliment' => 'OEUF_POUDRE', 'Id_Recette' => 2],
            ['Id_Aliment' => 'LAIT_POUDRE', 'Id_Recette' => 2],
            ['Id_Aliment' => 'POMME_TERRE', 'Id_Recette' => 3],
            ['Id_Aliment' => 'TOMATE', 'Id_Recette' => 4],
            ['Id_Aliment' => 'SALADE', 'Id_Recette' => 4],
            ['Id_Aliment' => 'LAIT_POUDRE', 'Id_Recette' => 5],
        ]);

        $this->insertAll($connection, 'Asso_11', [
            ['Id_Recette' => 1, 'Id_MOUVEMENT_STOCK' => 3],
            ['Id_Recette' => 4, 'Id_MOUVEMENT_STOCK' => 2],
        ]);

        $this->insertAll($connection, 'OCCUPANT_ALLERGIE', [
            ['Id_Equipage' => 1, 'Id_ALLERGENE' => 2],
            ['Id_Equipage' => 3, 'Id_ALLERGENE' => 1],
            ['Id_Equipage' => 4, 'Id_ALLERGENE' => 3],
        ]);
    }

    private function loadUsers(ObjectManager $manager): void
    {
        foreach ($manager->getRepository(User::class)->findAll() as $existingUser) {
            $manager->remove($existingUser);
        }
        $manager->flush();

        $demoUsers = [
            ['username' => 'admin', 'role' => 'ROLE_ADMIN'],
            ['username' => 'occupant', 'role' => 'ROLE_OCCUPANT'],
            ['username' => 'ferme', 'role' => 'ROLE_FERME'],
        ];

        foreach ($demoUsers as $demoUser) {
            $user = new User();
            $user->setUsername($demoUser['username']);
            $user->setRoles([$demoUser['role']]);
            $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));
            $manager->persist($user);
        }

        $manager->flush();
    }

    private function clear(Connection $connection): void
    {
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'OCCUPANT_ALLERGIE', 'Asso_11', 'recette_ingredient', 'PLANNING_REPAS_OCCUPANT',
            'PLANNING_REPAS', 'JOURNAL_REPAS', 'Equipage', 'MOUVEMENT_STOCK', 'LOT_STOCK',
            'RECOLTE', 'Recette', 'Aliment', 'type_repas', 'ALLERGENE', 'Activity_label',
            'categorie_recette', 'Categorie_ingredient', 'unite_stock',
        ] as $table) {
            $connection->executeStatement("DELETE FROM $table");
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertAll(Connection $connection, string $table, array $rows): void
    {
        foreach ($rows as $row) {
            $connection->insert($table, $row);
        }
    }
}
