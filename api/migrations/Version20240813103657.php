<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240813103657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs

        $this->addSql("INSERT INTO \"user\" (id, email, password, name) VALUES (1, 'user1@example.com', 'password1', 'User One');");
        $this->addSql("INSERT INTO \"user\" (id, email, password, name) VALUES (2, 'user2@example.com', 'password1', 'User One');");
        $this->addSql("INSERT INTO \"user\" (id, email, password, name) VALUES (3, 'user3@example.com', 'password1', 'User One');");

    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

    }
}
