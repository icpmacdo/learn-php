<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the single `link` table.
 *
 * Reviewed by hand (as every migration should be): BIGINT UNSIGNED surrogate
 * PK; code VARCHAR(7) in ascii_bin so lookups are case-sensitive ('a' != 'A')
 * and the unique index stays small; uniq_link_code is what LinkCreator's
 * collision handling relies on.
 */
final class Version20260718201115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the link table (unique base62 code, hit counter)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE link (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, code VARCHAR(7) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, url VARCHAR(2048) NOT NULL, hits INT UNSIGNED DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_link_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE link');
    }
}
