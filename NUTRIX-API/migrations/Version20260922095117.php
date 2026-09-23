<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922095117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creation du schema initial NUTRIX (occupants, stock, recoltes, recettes, repas)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE unite_stock (
            Id_unite_stock INT AUTO_INCREMENT NOT NULL,
            Libelle VARCHAR(5) DEFAULT NULL,
            PRIMARY KEY(Id_unite_stock)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE Categorie_ingredient (
            Id_Categorie_ingredient INT AUTO_INCREMENT NOT NULL,
            Libelle VARCHAR(50) NOT NULL,
            PRIMARY KEY(Id_Categorie_ingredient)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE categorie_recette (
            Id_categorie_recette INT AUTO_INCREMENT NOT NULL,
            libelle VARCHAR(50) NOT NULL,
            PRIMARY KEY(Id_categorie_recette),
            UNIQUE INDEX UNIQ_categorie_recette_libelle (libelle)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE Activity_label (
            Id_Activity_label INT AUTO_INCREMENT NOT NULL,
            libelle VARCHAR(50) DEFAULT NULL,
            PRIMARY KEY(Id_Activity_label)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE ALLERGENE (
            Id_ALLERGENE INT AUTO_INCREMENT NOT NULL,
            libelle VARCHAR(50) NOT NULL,
            PRIMARY KEY(Id_ALLERGENE)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE type_repas (
            Id_type_repas INT AUTO_INCREMENT NOT NULL,
            libelle VARCHAR(50) DEFAULT NULL,
            PRIMARY KEY(Id_type_repas)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE Aliment (
            Id_Aliment VARCHAR(50) NOT NULL,
            Libelle VARCHAR(50) NOT NULL,
            Kcal_100g DECIMAL(10, 2) NOT NULL,
            Proteines_100g DECIMAL(10, 2) NOT NULL,
            Glucides_100g DECIMAL(10, 2) NOT NULL,
            Lipides_100g DECIMAL(10, 2) NOT NULL,
            Fibres_100g DECIMAL(10, 2) NOT NULL,
            Cycle_jours_min TINYINT UNSIGNED NOT NULL,
            rendement_g_m2_j DECIMAL(15, 2) DEFAULT NULL,
            partie_replantable VARCHAR(50) DEFAULT NULL,
            Cycle_jours_max TINYINT UNSIGNED NOT NULL,
            Id_unite_stock INT DEFAULT NULL,
            Id_Categorie_ingredient INT DEFAULT NULL,
            PRIMARY KEY(Id_Aliment),
            INDEX IDX_Aliment_unite_stock (Id_unite_stock),
            INDEX IDX_Aliment_categorie_ingredient (Id_Categorie_ingredient)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE Recette (
            Id_Recette INT AUTO_INCREMENT NOT NULL,
            libelle VARCHAR(50) NOT NULL,
            proteines_g_100g VARCHAR(50) NOT NULL,
            glucides_g_100g VARCHAR(50) NOT NULL,
            lipides_g_100g VARCHAR(50) NOT NULL,
            fibres_g_100g VARCHAR(50) NOT NULL,
            poids_total_g DECIMAL(15, 2) NOT NULL,
            kcal_100g VARCHAR(50) NOT NULL,
            temps_preparation VARCHAR(50) DEFAULT NULL,
            cycle_production TINYINT UNSIGNED DEFAULT NULL,
            Id_categorie_recette INT DEFAULT NULL,
            PRIMARY KEY(Id_Recette),
            UNIQUE INDEX UNIQ_Recette_libelle (libelle),
            INDEX IDX_Recette_categorie_recette (Id_categorie_recette)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE RECOLTE (
            Id_RECOLTE INT AUTO_INCREMENT NOT NULL,
            module_culture VARCHAR(50) NOT NULL,
            date_semis DATE NOT NULL,
            date_recolte_prevue DATE NOT NULL,
            date_recolte_reelle DATE DEFAULT NULL,
            quantite_prevue_g DECIMAL(10, 2) NOT NULL,
            quantite_reelle_g DECIMAL(10, 2) DEFAULT NULL,
            statut VARCHAR(50) NOT NULL,
            taux_perte_pct DECIMAL(5, 2) DEFAULT NULL,
            Id_Aliment VARCHAR(50) NOT NULL,
            PRIMARY KEY(Id_RECOLTE),
            INDEX IDX_RECOLTE_Aliment (Id_Aliment)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE LOT_STOCK (
            Id_LOT_STOCK INT AUTO_INCREMENT NOT NULL,
            qr_code VARCHAR(50) NOT NULL,
            quantite_initiale_g DECIMAL(15, 2) NOT NULL,
            quantite_disponible_g DECIMAL(15, 2) NOT NULL,
            date_entree DATE NOT NULL,
            date_peremption DATE DEFAULT NULL,
            emplacement VARCHAR(50) NOT NULL,
            type_reserve VARCHAR(50) NOT NULL,
            statut VARCHAR(50) NOT NULL,
            Id_Aliment VARCHAR(50) NOT NULL,
            Id_RECOLTE INT DEFAULT NULL,
            PRIMARY KEY(Id_LOT_STOCK),
            INDEX IDX_LOT_STOCK_Aliment (Id_Aliment),
            INDEX IDX_LOT_STOCK_RECOLTE (Id_RECOLTE)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE MOUVEMENT_STOCK (
            Id_MOUVEMENT_STOCK INT AUTO_INCREMENT NOT NULL,
            type_mouvement VARCHAR(50) NOT NULL,
            quantite_g DECIMAL(15, 2) NOT NULL,
            date_mouvement DATETIME NOT NULL,
            Id_LOT_STOCK INT NOT NULL,
            PRIMARY KEY(Id_MOUVEMENT_STOCK),
            INDEX IDX_MOUVEMENT_STOCK_LOT_STOCK (Id_LOT_STOCK)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE Equipage (
            Id_Equipage INT AUTO_INCREMENT NOT NULL,
            sexe TINYINT(1) NOT NULL,
            age TINYINT UNSIGNED NOT NULL,
            poids_kilo DECIMAL(5, 2) NOT NULL,
            taille_cm TINYINT UNSIGNED NOT NULL,
            bmi DECIMAL(5, 2) NOT NULL,
            pal DECIMAL(2, 1) NOT NULL,
            Id_Activity_label INT NOT NULL,
            PRIMARY KEY(Id_Equipage),
            INDEX IDX_Equipage_Activity_label (Id_Activity_label)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE JOURNAL_REPAS (
            Id_JOURNAL_REPAS INT AUTO_INCREMENT NOT NULL,
            date_heure DATETIME NOT NULL,
            portion_g DECIMAL(6, 2) DEFAULT NULL,
            Id_Recette INT NOT NULL,
            Id_Equipage INT NOT NULL,
            Id_type_repas INT NOT NULL,
            PRIMARY KEY(Id_JOURNAL_REPAS),
            INDEX IDX_JOURNAL_REPAS_Recette (Id_Recette),
            INDEX IDX_JOURNAL_REPAS_Equipage (Id_Equipage),
            INDEX IDX_JOURNAL_REPAS_type_repas (Id_type_repas)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE PLANNING_REPAS (
            Id_PLANNING_REPAS INT AUTO_INCREMENT NOT NULL,
            date_ DATE NOT NULL,
            portions_prevues DECIMAL(6, 2) NOT NULL,
            type_repas VARCHAR(50) NOT NULL,
            Id_Recette INT NOT NULL,
            PRIMARY KEY(Id_PLANNING_REPAS),
            INDEX IDX_PLANNING_REPAS_Recette (Id_Recette)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE PLANNING_REPAS_OCCUPANT (
            Id_PLANNING_REPAS_OCCUPANT INT AUTO_INCREMENT NOT NULL,
            portion_ratio DECIMAL(6, 2) DEFAULT NULL,
            Id_Equipage INT NOT NULL,
            PRIMARY KEY(Id_PLANNING_REPAS_OCCUPANT),
            INDEX IDX_PLANNING_REPAS_OCCUPANT_Equipage (Id_Equipage)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE recette_ingredient (
            Id_Aliment VARCHAR(50) NOT NULL,
            Id_Recette INT NOT NULL,
            PRIMARY KEY(Id_Aliment, Id_Recette),
            INDEX IDX_recette_ingredient_Recette (Id_Recette)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE Asso_11 (
            Id_Recette INT NOT NULL,
            Id_MOUVEMENT_STOCK INT NOT NULL,
            PRIMARY KEY(Id_Recette, Id_MOUVEMENT_STOCK),
            INDEX IDX_Asso_11_MOUVEMENT_STOCK (Id_MOUVEMENT_STOCK)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE OCCUPANT_ALLERGIE (
            Id_Equipage INT NOT NULL,
            Id_ALLERGENE INT NOT NULL,
            PRIMARY KEY(Id_Equipage, Id_ALLERGENE),
            INDEX IDX_OCCUPANT_ALLERGIE_ALLERGENE (Id_ALLERGENE)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE Aliment ADD CONSTRAINT FK_Aliment_unite_stock FOREIGN KEY (Id_unite_stock) REFERENCES unite_stock (Id_unite_stock)');
        $this->addSql('ALTER TABLE Aliment ADD CONSTRAINT FK_Aliment_categorie_ingredient FOREIGN KEY (Id_Categorie_ingredient) REFERENCES Categorie_ingredient (Id_Categorie_ingredient)');
        $this->addSql('ALTER TABLE Recette ADD CONSTRAINT FK_Recette_categorie_recette FOREIGN KEY (Id_categorie_recette) REFERENCES categorie_recette (Id_categorie_recette)');
        $this->addSql('ALTER TABLE RECOLTE ADD CONSTRAINT FK_RECOLTE_Aliment FOREIGN KEY (Id_Aliment) REFERENCES Aliment (Id_Aliment)');
        $this->addSql('ALTER TABLE LOT_STOCK ADD CONSTRAINT FK_LOT_STOCK_Aliment FOREIGN KEY (Id_Aliment) REFERENCES Aliment (Id_Aliment)');
        $this->addSql('ALTER TABLE LOT_STOCK ADD CONSTRAINT FK_LOT_STOCK_RECOLTE FOREIGN KEY (Id_RECOLTE) REFERENCES RECOLTE (Id_RECOLTE)');
        $this->addSql('ALTER TABLE MOUVEMENT_STOCK ADD CONSTRAINT FK_MOUVEMENT_STOCK_LOT_STOCK FOREIGN KEY (Id_LOT_STOCK) REFERENCES LOT_STOCK (Id_LOT_STOCK)');
        $this->addSql('ALTER TABLE Equipage ADD CONSTRAINT FK_Equipage_Activity_label FOREIGN KEY (Id_Activity_label) REFERENCES Activity_label (Id_Activity_label)');
        $this->addSql('ALTER TABLE JOURNAL_REPAS ADD CONSTRAINT FK_JOURNAL_REPAS_Recette FOREIGN KEY (Id_Recette) REFERENCES Recette (Id_Recette)');
        $this->addSql('ALTER TABLE JOURNAL_REPAS ADD CONSTRAINT FK_JOURNAL_REPAS_Equipage FOREIGN KEY (Id_Equipage) REFERENCES Equipage (Id_Equipage)');
        $this->addSql('ALTER TABLE JOURNAL_REPAS ADD CONSTRAINT FK_JOURNAL_REPAS_type_repas FOREIGN KEY (Id_type_repas) REFERENCES type_repas (Id_type_repas)');
        $this->addSql('ALTER TABLE PLANNING_REPAS ADD CONSTRAINT FK_PLANNING_REPAS_Recette FOREIGN KEY (Id_Recette) REFERENCES Recette (Id_Recette)');
        $this->addSql('ALTER TABLE PLANNING_REPAS_OCCUPANT ADD CONSTRAINT FK_PLANNING_REPAS_OCCUPANT_Equipage FOREIGN KEY (Id_Equipage) REFERENCES Equipage (Id_Equipage)');
        $this->addSql('ALTER TABLE recette_ingredient ADD CONSTRAINT FK_recette_ingredient_Aliment FOREIGN KEY (Id_Aliment) REFERENCES Aliment (Id_Aliment)');
        $this->addSql('ALTER TABLE recette_ingredient ADD CONSTRAINT FK_recette_ingredient_Recette FOREIGN KEY (Id_Recette) REFERENCES Recette (Id_Recette)');
        $this->addSql('ALTER TABLE Asso_11 ADD CONSTRAINT FK_Asso_11_Recette FOREIGN KEY (Id_Recette) REFERENCES Recette (Id_Recette)');
        $this->addSql('ALTER TABLE Asso_11 ADD CONSTRAINT FK_Asso_11_MOUVEMENT_STOCK FOREIGN KEY (Id_MOUVEMENT_STOCK) REFERENCES MOUVEMENT_STOCK (Id_MOUVEMENT_STOCK)');
        $this->addSql('ALTER TABLE OCCUPANT_ALLERGIE ADD CONSTRAINT FK_OCCUPANT_ALLERGIE_Equipage FOREIGN KEY (Id_Equipage) REFERENCES Equipage (Id_Equipage)');
        $this->addSql('ALTER TABLE OCCUPANT_ALLERGIE ADD CONSTRAINT FK_OCCUPANT_ALLERGIE_ALLERGENE FOREIGN KEY (Id_ALLERGENE) REFERENCES ALLERGENE (Id_ALLERGENE)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE OCCUPANT_ALLERGIE DROP FOREIGN KEY FK_OCCUPANT_ALLERGIE_Equipage');
        $this->addSql('ALTER TABLE OCCUPANT_ALLERGIE DROP FOREIGN KEY FK_OCCUPANT_ALLERGIE_ALLERGENE');
        $this->addSql('ALTER TABLE Asso_11 DROP FOREIGN KEY FK_Asso_11_Recette');
        $this->addSql('ALTER TABLE Asso_11 DROP FOREIGN KEY FK_Asso_11_MOUVEMENT_STOCK');
        $this->addSql('ALTER TABLE recette_ingredient DROP FOREIGN KEY FK_recette_ingredient_Aliment');
        $this->addSql('ALTER TABLE recette_ingredient DROP FOREIGN KEY FK_recette_ingredient_Recette');
        $this->addSql('ALTER TABLE PLANNING_REPAS_OCCUPANT DROP FOREIGN KEY FK_PLANNING_REPAS_OCCUPANT_Equipage');
        $this->addSql('ALTER TABLE PLANNING_REPAS DROP FOREIGN KEY FK_PLANNING_REPAS_Recette');
        $this->addSql('ALTER TABLE JOURNAL_REPAS DROP FOREIGN KEY FK_JOURNAL_REPAS_Recette');
        $this->addSql('ALTER TABLE JOURNAL_REPAS DROP FOREIGN KEY FK_JOURNAL_REPAS_Equipage');
        $this->addSql('ALTER TABLE JOURNAL_REPAS DROP FOREIGN KEY FK_JOURNAL_REPAS_type_repas');
        $this->addSql('ALTER TABLE Equipage DROP FOREIGN KEY FK_Equipage_Activity_label');
        $this->addSql('ALTER TABLE MOUVEMENT_STOCK DROP FOREIGN KEY FK_MOUVEMENT_STOCK_LOT_STOCK');
        $this->addSql('ALTER TABLE LOT_STOCK DROP FOREIGN KEY FK_LOT_STOCK_Aliment');
        $this->addSql('ALTER TABLE LOT_STOCK DROP FOREIGN KEY FK_LOT_STOCK_RECOLTE');
        $this->addSql('ALTER TABLE RECOLTE DROP FOREIGN KEY FK_RECOLTE_Aliment');
        $this->addSql('ALTER TABLE Recette DROP FOREIGN KEY FK_Recette_categorie_recette');
        $this->addSql('ALTER TABLE Aliment DROP FOREIGN KEY FK_Aliment_unite_stock');
        $this->addSql('ALTER TABLE Aliment DROP FOREIGN KEY FK_Aliment_categorie_ingredient');

        $this->addSql('DROP TABLE OCCUPANT_ALLERGIE');
        $this->addSql('DROP TABLE Asso_11');
        $this->addSql('DROP TABLE recette_ingredient');
        $this->addSql('DROP TABLE PLANNING_REPAS_OCCUPANT');
        $this->addSql('DROP TABLE PLANNING_REPAS');
        $this->addSql('DROP TABLE JOURNAL_REPAS');
        $this->addSql('DROP TABLE Equipage');
        $this->addSql('DROP TABLE MOUVEMENT_STOCK');
        $this->addSql('DROP TABLE LOT_STOCK');
        $this->addSql('DROP TABLE RECOLTE');
        $this->addSql('DROP TABLE Recette');
        $this->addSql('DROP TABLE Aliment');
        $this->addSql('DROP TABLE type_repas');
        $this->addSql('DROP TABLE ALLERGENE');
        $this->addSql('DROP TABLE Activity_label');
        $this->addSql('DROP TABLE categorie_recette');
        $this->addSql('DROP TABLE Categorie_ingredient');
        $this->addSql('DROP TABLE unite_stock');
    }
}
