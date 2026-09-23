<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923091500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Annule le renommage de Asso_11 : le moteur de calcul (src/Service/Moteur) reference ce nom de table en dur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('RENAME TABLE RECETTE_MOUVEMENT_STOCK TO Asso_11');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('RENAME TABLE Asso_11 TO RECETTE_MOUVEMENT_STOCK');
    }
}
