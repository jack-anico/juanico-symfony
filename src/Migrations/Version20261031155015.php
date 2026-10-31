<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031155015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE discount_code (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, type VARCHAR(10) NOT NULL, value NUMERIC(10, 2) NOT NULL, min_spend NUMERIC(10, 2) DEFAULT NULL, usage_limit INT DEFAULT NULL, times_used INT NOT NULL, expires_at DATETIME DEFAULT NULL, is_active TINYINT NOT NULL, UNIQUE INDEX UNIQ_E997352277153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE discount_code');
    }
}
