<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261101013431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `order` ADD idempotency_key VARCHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F52993987FD1C147 ON `order` (idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_F52993987FD1C147 ON `order`');
        $this->addSql('ALTER TABLE `order` DROP idempotency_key');
    }
}
