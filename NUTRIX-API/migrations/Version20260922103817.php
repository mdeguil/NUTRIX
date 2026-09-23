<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922103817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomme la colonne user.email en user.username';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user CHANGE email username VARCHAR(180) NOT NULL');
        $this->addSql('ALTER TABLE user RENAME INDEX UNIQ_IDENTIFIER_EMAIL TO UNIQ_IDENTIFIER_USERNAME');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user CHANGE username email VARCHAR(180) NOT NULL');
        $this->addSql('ALTER TABLE user RENAME INDEX UNIQ_IDENTIFIER_USERNAME TO UNIQ_IDENTIFIER_EMAIL');
    }
}
