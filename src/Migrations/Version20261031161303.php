<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031161303 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_movement ADD admin_id INT DEFAULT NULL, DROP many_to_one, DROP user, CHANGE no reason VARCHAR(30) NOT NULL, CHANGE note reasonte VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5642B8210 FOREIGN KEY (admin_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_BB1BC1B5642B8210 ON stock_movement (admin_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B5642B8210');
        $this->addSql('DROP INDEX IDX_BB1BC1B5642B8210 ON stock_movement');
        $this->addSql('ALTER TABLE stock_movement ADD many_to_one VARCHAR(255) NOT NULL, ADD user VARCHAR(255) NOT NULL, DROP admin_id, CHANGE reason no VARCHAR(30) NOT NULL, CHANGE reasonte note VARCHAR(255) DEFAULT NULL');
    }
}
