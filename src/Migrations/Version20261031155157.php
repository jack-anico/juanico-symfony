<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031155157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `order` (id INT AUTO_INCREMENT NOT NULL, order_number VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, payment_method VARCHAR(30) NOT NULL, payment_status VARCHAR(20) NOT NULL, shipping_method VARCHAR(20) NOT NULL, payment_proof VARCHAR(255) DEFAULT NULL, cancel_reason LONGTEXT DEFAULT NULL, subtotal NUMERIC(10, 2) NOT NULL, shipping_fee NUMERIC(10, 2) NOT NULL, discount NUMERIC(10, 2) NOT NULL, total NUMERIC(10, 2) NOT NULL, notes LONGTEXT DEFAULT NULL, tracking_number VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, paid_at DATETIME DEFAULT NULL, delivered_at DATETIME DEFAULT NULL, customer_id INT NOT NULL, shipping_address_id INT NOT NULL, discount_code_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_F5299398551F0F81 (order_number), INDEX IDX_F52993989395C3F3 (customer_id), INDEX IDX_F52993984D4CFF2B (shipping_address_id), INDEX IDX_F529939891D29306 (discount_code_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F52993989395C3F3 FOREIGN KEY (customer_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F52993984D4CFF2B FOREIGN KEY (shipping_address_id) REFERENCES address (id)');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F529939891D29306 FOREIGN KEY (discount_code_id) REFERENCES discount_code (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F52993989395C3F3');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F52993984D4CFF2B');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F529939891D29306');
        $this->addSql('DROP TABLE `order`');
    }
}
