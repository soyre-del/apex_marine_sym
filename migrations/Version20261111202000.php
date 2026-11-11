<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111202000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete all client accounts and their connected history except the Laurence client account.';
    }

    public function up(Schema $schema): void
    {
        // 1. Delete operation_updates linked to dispatch_requests of non-Laurence clients
        $this->addSql(<<<'SQL'
            DELETE ou FROM operation_updates ou
            INNER JOIN dispatch_requests dr ON ou.request_id = dr.request_id
            INNER JOIN users u ON dr.client_id = u.user_id
            WHERE u.role = 'client'
              AND LOWER(COALESCE(u.first_name, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.username, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.surname, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.email, '')) NOT LIKE '%laurence%'
            SQL);

        // 2. Delete operation_updates authored by non-Laurence clients
        $this->addSql(<<<'SQL'
            DELETE ou FROM operation_updates ou
            INNER JOIN users u ON ou.updater_id = u.user_id
            WHERE u.role = 'client'
              AND LOWER(COALESCE(u.first_name, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.username, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.surname, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.email, '')) NOT LIKE '%laurence%'
            SQL);

        // 3. Delete deployments linked to dispatch_requests of non-Laurence clients
        $this->addSql(<<<'SQL'
            DELETE d FROM deployments d
            INNER JOIN dispatch_requests dr ON d.request_id = dr.request_id
            INNER JOIN users u ON dr.client_id = u.user_id
            WHERE u.role = 'client'
              AND LOWER(COALESCE(u.first_name, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.username, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.surname, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.email, '')) NOT LIKE '%laurence%'
            SQL);

        // 4. Delete invoices linked to dispatch_requests of non-Laurence clients
        $this->addSql(<<<'SQL'
            DELETE i FROM invoices i
            INNER JOIN dispatch_requests dr ON i.request_id = dr.request_id
            INNER JOIN users u ON dr.client_id = u.user_id
            WHERE u.role = 'client'
              AND LOWER(COALESCE(u.first_name, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.username, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.surname, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.email, '')) NOT LIKE '%laurence%'
            SQL);

        // 5. Delete invoices directly referencing non-Laurence clients
        $this->addSql(<<<'SQL'
            DELETE i FROM invoices i
            INNER JOIN users u ON i.client_id = u.user_id
            WHERE u.role = 'client'
              AND LOWER(COALESCE(u.first_name, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.username, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.surname, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.email, '')) NOT LIKE '%laurence%'
            SQL);

        // 6. Delete dispatch_requests belonging to non-Laurence clients
        $this->addSql(<<<'SQL'
            DELETE dr FROM dispatch_requests dr
            INNER JOIN users u ON dr.client_id = u.user_id
            WHERE u.role = 'client'
              AND LOWER(COALESCE(u.first_name, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.username, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.surname, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.email, '')) NOT LIKE '%laurence%'
            SQL);

        // 7. Delete client_profiles belonging to non-Laurence clients
        $this->addSql(<<<'SQL'
            DELETE cp FROM client_profiles cp
            INNER JOIN users u ON cp.client_id = u.user_id
            WHERE u.role = 'client'
              AND LOWER(COALESCE(u.first_name, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.username, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.surname, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(u.email, '')) NOT LIKE '%laurence%'
            SQL);

        // 8. Delete non-Laurence client accounts from users
        $this->addSql(<<<'SQL'
            DELETE FROM users
            WHERE role = 'client'
              AND LOWER(COALESCE(first_name, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(username, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(surname, '')) NOT LIKE '%laurence%'
              AND LOWER(COALESCE(email, '')) NOT LIKE '%laurence%'
            SQL);

        // 9. Reset any engineers whose deployments were removed back to available
        $this->addSql(<<<'SQL'
            UPDATE engineer_profiles ep
            SET ep.current_status = 'available'
            WHERE ep.current_status = 'deployed'
              AND NOT EXISTS (
                  SELECT 1
                  FROM deployments d
                  WHERE d.engineer_id = ep.engineer_id
                    AND d.deployment_status <> 'completed'
              )
            SQL);

        $this->addSql("ALTER TABLE client_profiles COMMENT = 'laurence_cleanup_v1'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE client_profiles COMMENT = ''");
    }
}
