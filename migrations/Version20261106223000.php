<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the users table used by the User security entity and application queries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE users (user_id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(100) NOT NULL, email VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, role ENUM('client', 'engineer', 'admin') NOT NULL DEFAULT 'client', is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_USERS_EMAIL (email), PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}
