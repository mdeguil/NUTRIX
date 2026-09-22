<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute recette_ingredient.quantite_g (manquant au MLD initial, cf. MOTEUR_CALCUL.md ecart #1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recette_ingredient ADD quantite_g DECIMAL(10,2) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recette_ingredient DROP quantite_g');
    }
}
