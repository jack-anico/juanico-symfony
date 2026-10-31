<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031155653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE wishlist_item (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, product_id INT NOT NULL, INDEX IDX_6424F4E87E3C61F9 (owner_id), INDEX IDX_6424F4E84584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE wishlist_item ADD CONSTRAINT FK_6424F4E87E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE wishlist_item ADD CONSTRAINT FK_6424F4E84584665A FOREIGN KEY (product_id) REFERENCES product (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wishlist_item DROP FOREIGN KEY FK_6424F4E87E3C61F9');
        $this->addSql('ALTER TABLE wishlist_item DROP FOREIGN KEY FK_6424F4E84584665A');
        $this->addSql('DROP TABLE wishlist_item');
    }
}
