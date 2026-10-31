<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031154835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE address (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(50) NOT NULL, recipient VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL, line1 VARCHAR(255) NOT NULL, city VARCHAR(100) NOT NULL, province VARCHAR(100) NOT NULL, postal_code VARCHAR(20) NOT NULL, is_default TINYINT NOT NULL, owner_id INT NOT NULL, INDEX IDX_D4E6F817E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE address ADD CONSTRAINT FK_D4E6F817E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE address DROP FOREIGN KEY FK_D4E6F817E3C61F9');
        $this->addSql('DROP TABLE address');
    }
}
