<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la FK manquante PLANNING_REPAS_OCCUPANT -> PLANNING_REPAS (oubli du schema initial) et renomme Asso_11 en RECETTE_MOUVEMENT_STOCK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM PLANNING_REPAS_OCCUPANT');
        $this->addSql('ALTER TABLE PLANNING_REPAS_OCCUPANT ADD Id_PLANNING_REPAS INT NOT NULL');
        $this->addSql('ALTER TABLE PLANNING_REPAS_OCCUPANT ADD CONSTRAINT FK_PLANNING_REPAS_OCCUPANT_PlanningRepas FOREIGN KEY (Id_PLANNING_REPAS) REFERENCES PLANNING_REPAS (Id_PLANNING_REPAS)');
        $this->addSql('CREATE INDEX IDX_PLANNING_REPAS_OCCUPANT_PlanningRepas ON PLANNING_REPAS_OCCUPANT (Id_PLANNING_REPAS)');

        $this->addSql('RENAME TABLE Asso_11 TO RECETTE_MOUVEMENT_STOCK');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('RENAME TABLE RECETTE_MOUVEMENT_STOCK TO Asso_11');

        $this->addSql('ALTER TABLE PLANNING_REPAS_OCCUPANT DROP FOREIGN KEY FK_PLANNING_REPAS_OCCUPANT_PlanningRepas');
        $this->addSql('DROP INDEX IDX_PLANNING_REPAS_OCCUPANT_PlanningRepas ON PLANNING_REPAS_OCCUPANT');
        $this->addSql('ALTER TABLE PLANNING_REPAS_OCCUPANT DROP Id_PLANNING_REPAS');
    }
}
