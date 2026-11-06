<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107014313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE client_profiles (client_id INT NOT NULL, company_name VARCHAR(150) DEFAULT NULL, contact_number VARCHAR(50) DEFAULT NULL, PRIMARY KEY (client_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE deployments (deployment_id INT AUTO_INCREMENT NOT NULL, deployment_status ENUM(\'en_route\',\'on_site\',\'completed\') DEFAULT \'en_route\', deployed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, request_id INT NOT NULL, admin_id INT NOT NULL, engineer_id INT NOT NULL, UNIQUE INDEX UNIQ_373C43D5427EB8A5 (request_id), INDEX IDX_373C43D5642B8210 (admin_id), INDEX IDX_373C43D5F8D8CDF1 (engineer_id), PRIMARY KEY (deployment_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE engineer_profiles (engineer_id INT NOT NULL, specialty VARCHAR(100) NOT NULL, current_status ENUM(\'available\',\'deployed\',\'on_leave\') DEFAULT \'available\', PRIMARY KEY (engineer_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invoices (invoice_id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) DEFAULT \'USD\', payment_method VARCHAR(50) DEFAULT NULL, reference_number VARCHAR(100) DEFAULT NULL, payment_status ENUM(\'unpaid\',\'paid\',\'failed\') DEFAULT \'unpaid\', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, request_id INT NOT NULL, client_id INT NOT NULL, INDEX IDX_6A2F2F95427EB8A5 (request_id), INDEX IDX_6A2F2F9519EB6921 (client_id), PRIMARY KEY (invoice_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE operation_updates (update_id INT AUTO_INCREMENT NOT NULL, status_milestone VARCHAR(100) NOT NULL, detailed_message LONGTEXT NOT NULL, attachment_path VARCHAR(255) DEFAULT NULL, logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, request_id INT NOT NULL, updater_id INT NOT NULL, INDEX IDX_E68993FD427EB8A5 (request_id), INDEX IDX_E68993FDE37ECFB0 (updater_id), PRIMARY KEY (update_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_locations (location_id INT AUTO_INCREMENT NOT NULL, port_name VARCHAR(100) NOT NULL, hub_id INT NOT NULL, INDEX IDX_4F7770F06C786081 (hub_id), PRIMARY KEY (location_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5642B8210 FOREIGN KEY (admin_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5F8D8CDF1 FOREIGN KEY (engineer_id) REFERENCES engineer_profiles (engineer_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F95427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F9519EB6921 FOREIGN KEY (client_id) REFERENCES client_profiles (client_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE operation_updates ADD CONSTRAINT FK_E68993FD427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE operation_updates ADD CONSTRAINT FK_E68993FDE37ECFB0 FOREIGN KEY (updater_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE service_locations ADD CONSTRAINT FK_4F7770F06C786081 FOREIGN KEY (hub_id) REFERENCES operational_hubs (hub_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE dispatch_requests CHANGE vessel_name vessel_name VARCHAR(100) NOT NULL, CHANGE imo_number imo_number VARCHAR(50) NOT NULL, CHANGE vessel_type vessel_type VARCHAR(100) NOT NULL, CHANGE contact_person contact_person VARCHAR(100) NOT NULL, CHANGE service_type service_type VARCHAR(100) NOT NULL, CHANGE is_urgent is_urgent TINYINT DEFAULT 0, CHANGE attachment_path attachment_path VARCHAR(255) DEFAULT NULL, CHANGE company company VARCHAR(100) DEFAULT NULL, CHANGE status status ENUM(\'pending\',\'acknowledged\',\'deployed\',\'in_progress\',\'resolved\',\'cancelled\') DEFAULT \'pending\', CHANGE is_active is_active TINYINT DEFAULT 1, CHANGE requested_at requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE dispatch_requests ADD CONSTRAINT FK_9C158BC719EB6921 FOREIGN KEY (client_id) REFERENCES client_profiles (client_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE dispatch_requests ADD CONSTRAINT FK_9C158BC764D218E FOREIGN KEY (location_id) REFERENCES service_locations (location_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('CREATE INDEX IDX_9C158BC719EB6921 ON dispatch_requests (client_id)');
        $this->addSql('CREATE INDEX IDX_9C158BC764D218E ON dispatch_requests (location_id)');
        $this->addSql('ALTER TABLE users CHANGE role role ENUM(\'client\',\'engineer\',\'admin\') NOT NULL DEFAULT \'client\', CHANGE created_at created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5427EB8A5');
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5642B8210');
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5F8D8CDF1');
        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F95427EB8A5');
        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F9519EB6921');
        $this->addSql('ALTER TABLE operation_updates DROP FOREIGN KEY FK_E68993FD427EB8A5');
        $this->addSql('ALTER TABLE operation_updates DROP FOREIGN KEY FK_E68993FDE37ECFB0');
        $this->addSql('ALTER TABLE service_locations DROP FOREIGN KEY FK_4F7770F06C786081');
        $this->addSql('DROP TABLE client_profiles');
        $this->addSql('DROP TABLE deployments');
        $this->addSql('DROP TABLE engineer_profiles');
        $this->addSql('DROP TABLE invoices');
        $this->addSql('DROP TABLE operation_updates');
        $this->addSql('DROP TABLE service_locations');
        $this->addSql('ALTER TABLE dispatch_requests DROP FOREIGN KEY FK_9C158BC719EB6921');
        $this->addSql('ALTER TABLE dispatch_requests DROP FOREIGN KEY FK_9C158BC764D218E');
        $this->addSql('DROP INDEX IDX_9C158BC719EB6921 ON dispatch_requests');
        $this->addSql('DROP INDEX IDX_9C158BC764D218E ON dispatch_requests');
        $this->addSql('ALTER TABLE dispatch_requests CHANGE vessel_name vessel_name VARCHAR(255) NOT NULL, CHANGE imo_number imo_number VARCHAR(7) NOT NULL, CHANGE vessel_type vessel_type VARCHAR(255) NOT NULL, CHANGE contact_person contact_person VARCHAR(255) NOT NULL, CHANGE service_type service_type VARCHAR(255) NOT NULL, CHANGE is_urgent is_urgent TINYINT NOT NULL, CHANGE attachment_path attachment_path VARCHAR(100) DEFAULT NULL, CHANGE company company VARCHAR(255) DEFAULT NULL, CHANGE status status VARCHAR(50) NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE requested_at requested_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE role role ENUM(\'client\', \'engineer\', \'admin\') DEFAULT \'client\' NOT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
    }
}
