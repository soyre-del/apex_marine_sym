<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106213600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reset dispatch requests with the request_id and requested_at columns used by the application.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE dispatch_requests');
        $this->addSql('CREATE TABLE dispatch_requests (request_id INT AUTO_INCREMENT NOT NULL, vessel_name VARCHAR(255) NOT NULL, imo_number VARCHAR(7) NOT NULL, vessel_type VARCHAR(255) NOT NULL, contact_person VARCHAR(255) NOT NULL, contact_phone VARCHAR(50) NOT NULL, service_type VARCHAR(255) NOT NULL, is_urgent TINYINT NOT NULL, description LONGTEXT NOT NULL, attachment_path VARCHAR(100) DEFAULT NULL, client_id INT NOT NULL, company VARCHAR(255) DEFAULT NULL, location_id INT NOT NULL, status VARCHAR(50) NOT NULL, is_active TINYINT NOT NULL, eta_date DATE NOT NULL, requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY (request_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dispatch_requests');
        $this->addSql('CREATE TABLE dispatch_requests (id INT AUTO_INCREMENT NOT NULL, vessel_name VARCHAR(255) NOT NULL, imo_number VARCHAR(7) NOT NULL, vessel_type VARCHAR(255) NOT NULL, contact_person VARCHAR(255) NOT NULL, contact_phone VARCHAR(50) NOT NULL, service_type VARCHAR(255) NOT NULL, is_urgent TINYINT NOT NULL, description LONGTEXT NOT NULL, attachment_path VARCHAR(100) DEFAULT NULL, client_id INT NOT NULL, company VARCHAR(255) DEFAULT NULL, location_id INT NOT NULL, status VARCHAR(50) NOT NULL, is_active TINYINT NOT NULL, eta_date DATE NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }
}
