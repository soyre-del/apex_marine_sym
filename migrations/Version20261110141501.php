<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110141501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove ON UPDATE CASCADE from application foreign keys.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5427EB8A5');
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5642B8210');
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5F8D8CDF1');
        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F95427EB8A5');
        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F9519EB6921');
        $this->addSql('ALTER TABLE operation_updates DROP FOREIGN KEY FK_E68993FD427EB8A5');
        $this->addSql('ALTER TABLE operation_updates DROP FOREIGN KEY FK_E68993FDE37ECFB0');
        $this->addSql('ALTER TABLE service_locations DROP FOREIGN KEY FK_4F7770F06C786081');
        $this->addSql('ALTER TABLE dispatch_requests DROP FOREIGN KEY FK_9C158BC719EB6921');
        $this->addSql('ALTER TABLE dispatch_requests DROP FOREIGN KEY FK_9C158BC764D218E');

        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5642B8210 FOREIGN KEY (admin_id) REFERENCES users (user_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5F8D8CDF1 FOREIGN KEY (engineer_id) REFERENCES engineer_profiles (engineer_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F95427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F9519EB6921 FOREIGN KEY (client_id) REFERENCES client_profiles (client_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE operation_updates ADD CONSTRAINT FK_E68993FD427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE operation_updates ADD CONSTRAINT FK_E68993FDE37ECFB0 FOREIGN KEY (updater_id) REFERENCES users (user_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_locations ADD CONSTRAINT FK_4F7770F06C786081 FOREIGN KEY (hub_id) REFERENCES operational_hubs (hub_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE dispatch_requests ADD CONSTRAINT FK_9C158BC719EB6921 FOREIGN KEY (client_id) REFERENCES client_profiles (client_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE dispatch_requests ADD CONSTRAINT FK_9C158BC764D218E FOREIGN KEY (location_id) REFERENCES service_locations (location_id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5427EB8A5');
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5642B8210');
        $this->addSql('ALTER TABLE deployments DROP FOREIGN KEY FK_373C43D5F8D8CDF1');
        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F95427EB8A5');
        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F9519EB6921');
        $this->addSql('ALTER TABLE operation_updates DROP FOREIGN KEY FK_E68993FD427EB8A5');
        $this->addSql('ALTER TABLE operation_updates DROP FOREIGN KEY FK_E68993FDE37ECFB0');
        $this->addSql('ALTER TABLE service_locations DROP FOREIGN KEY FK_4F7770F06C786081');
        $this->addSql('ALTER TABLE dispatch_requests DROP FOREIGN KEY FK_9C158BC719EB6921');
        $this->addSql('ALTER TABLE dispatch_requests DROP FOREIGN KEY FK_9C158BC764D218E');

        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5642B8210 FOREIGN KEY (admin_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE deployments ADD CONSTRAINT FK_373C43D5F8D8CDF1 FOREIGN KEY (engineer_id) REFERENCES engineer_profiles (engineer_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F95427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F9519EB6921 FOREIGN KEY (client_id) REFERENCES client_profiles (client_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE operation_updates ADD CONSTRAINT FK_E68993FD427EB8A5 FOREIGN KEY (request_id) REFERENCES dispatch_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE operation_updates ADD CONSTRAINT FK_E68993FDE37ECFB0 FOREIGN KEY (updater_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE service_locations ADD CONSTRAINT FK_4F7770F06C786081 FOREIGN KEY (hub_id) REFERENCES operational_hubs (hub_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE dispatch_requests ADD CONSTRAINT FK_9C158BC719EB6921 FOREIGN KEY (client_id) REFERENCES client_profiles (client_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE dispatch_requests ADD CONSTRAINT FK_9C158BC764D218E FOREIGN KEY (location_id) REFERENCES service_locations (location_id) ON DELETE RESTRICT ON UPDATE CASCADE');
    }
}
