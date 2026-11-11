<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alter users table to add username and replace full_name with first_name, middle_name (optional), and surname.';
    }

    public function up(Schema $schema): void
    {
        $columns = $this->connection->fetchFirstColumn('SHOW COLUMNS FROM users');
        $columnMap = array_flip(array_map('strtolower', $columns));

        if (!isset($columnMap['username'])) {
            $this->addSql('ALTER TABLE users ADD COLUMN username VARCHAR(60) DEFAULT NULL AFTER user_id');
        }

        if (!isset($columnMap['first_name'])) {
            $this->addSql("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER username");
        }

        if (!isset($columnMap['middle_name'])) {
            $this->addSql('ALTER TABLE users ADD COLUMN middle_name VARCHAR(100) DEFAULT NULL AFTER first_name');
        }

        if (!isset($columnMap['surname'])) {
            $this->addSql("ALTER TABLE users ADD COLUMN surname VARCHAR(100) NOT NULL DEFAULT '' AFTER middle_name");
        }

        if (isset($columnMap['full_name'])) {
            $this->addSql(<<<'SQL'
                UPDATE users
                SET
                    first_name = CASE
                        WHEN TRIM(full_name) = '' THEN 'User'
                        ELSE SUBSTRING_INDEX(TRIM(full_name), ' ', 1)
                    END,
                    middle_name = CASE
                        WHEN LENGTH(TRIM(full_name)) - LENGTH(REPLACE(TRIM(full_name), ' ', '')) >= 2 THEN
                            TRIM(SUBSTRING(
                                TRIM(full_name),
                                LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)) + 2,
                                LENGTH(TRIM(full_name)) - LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)) - LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', -1)) - 2
                            ))
                        ELSE NULL
                    END,
                    surname = CASE
                        WHEN INSTR(TRIM(full_name), ' ') > 0 THEN SUBSTRING_INDEX(TRIM(full_name), ' ', -1)
                        ELSE ''
                    END
                WHERE first_name = ''
                SQL);

            $this->addSql('ALTER TABLE users DROP COLUMN full_name');
        }

        $this->addSql("UPDATE users SET username = SUBSTRING_INDEX(email, '@', 1) WHERE username IS NULL OR TRIM(username) = ''");
    }

    public function down(Schema $schema): void
    {
        $columns = $this->connection->fetchFirstColumn('SHOW COLUMNS FROM users');
        $columnMap = array_flip(array_map('strtolower', $columns));

        if (!isset($columnMap['full_name'])) {
            $this->addSql("ALTER TABLE users ADD COLUMN full_name VARCHAR(100) NOT NULL DEFAULT '' AFTER user_id");
            $this->addSql("UPDATE users SET full_name = TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(middle_name, ''), NULLIF(surname, '')))");
        }

        if (isset($columnMap['username'])) {
            $this->addSql('ALTER TABLE users DROP COLUMN username');
        }
        if (isset($columnMap['first_name'])) {
            $this->addSql('ALTER TABLE users DROP COLUMN first_name');
        }
        if (isset($columnMap['middle_name'])) {
            $this->addSql('ALTER TABLE users DROP COLUMN middle_name');
        }
        if (isset($columnMap['surname'])) {
            $this->addSql('ALTER TABLE users DROP COLUMN surname');
        }
    }
}
