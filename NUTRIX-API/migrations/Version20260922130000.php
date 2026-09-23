<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relie Equipage a user (Id_User, nullable, 1-1) pour associer un profil equipage a un compte de connexion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Equipage ADD Id_User INT DEFAULT NULL');
        $this->addSql('ALTER TABLE Equipage ADD CONSTRAINT FK_Equipage_User FOREIGN KEY (Id_User) REFERENCES user (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_Equipage_User ON Equipage (Id_User)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Equipage DROP FOREIGN KEY FK_Equipage_User');
        $this->addSql('DROP INDEX UNIQ_Equipage_User ON Equipage');
        $this->addSql('ALTER TABLE Equipage DROP Id_User');
    }
}
