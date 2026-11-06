<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106144610 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE operational_hubs (hub_id INT AUTO_INCREMENT NOT NULL, hub_name VARCHAR(100) NOT NULL, hub_type VARCHAR(50) DEFAULT \'Standard\', PRIMARY KEY (hub_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dispatch_requests CHANGE requested_at requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE role role ENUM(\'client\',\'engineer\',\'admin\') NOT NULL DEFAULT \'client\', CHANGE created_at created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE operational_hubs');
        $this->addSql('ALTER TABLE dispatch_requests CHANGE requested_at requested_at DATETIME DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE users CHANGE role role ENUM(\'client\', \'engineer\', \'admin\') DEFAULT \'client\' NOT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
    }
}
