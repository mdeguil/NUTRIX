<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colonnes demandees par le front (Docs/API_REQUETES_FRONT.md §12) :
 *  - Equipage : nom, prenom, fonction a bord, avatar ;
 *  - JOURNAL_REPAS : notes d'un repas ;
 *  - Recette.libelle passe de 50 a 150 caracteres (« Menu C — Boeuf seche, pates completes, haricots verts »).
 *
 * Tout est nullable : les lignes existantes restent valides. IF NOT EXISTS rend la migration
 * rejouable sans erreur (elle est lancee au demarrage du conteneur, cf. Dockerfile.vercel).
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute nom/prenom/fonction/avatar_url sur Equipage, notes sur JOURNAL_REPAS et agrandit Recette.libelle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Equipage ADD COLUMN IF NOT EXISTS nom VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE Equipage ADD COLUMN IF NOT EXISTS prenom VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE Equipage ADD COLUMN IF NOT EXISTS fonction VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE Equipage ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE JOURNAL_REPAS ADD COLUMN IF NOT EXISTS notes VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE Recette ALTER COLUMN libelle TYPE VARCHAR(150)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Recette ALTER COLUMN libelle TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE JOURNAL_REPAS DROP COLUMN IF EXISTS notes');
        $this->addSql('ALTER TABLE Equipage DROP COLUMN IF EXISTS avatar_url');
        $this->addSql('ALTER TABLE Equipage DROP COLUMN IF EXISTS fonction');
        $this->addSql('ALTER TABLE Equipage DROP COLUMN IF EXISTS prenom');
        $this->addSql('ALTER TABLE Equipage DROP COLUMN IF EXISTS nom');
    }
}
