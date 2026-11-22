<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the journal of the agents' analyses as the mapping describes it, unless it exists: an application
 * that created it with migrations of its own runs this one as a no-op, recorded as done.
 * MySQL, MariaDB and SQLite; on another platform, write the table's migration yourself
 * (`doctrine:schema:update --dump-sql` gives it).
 */
final class Version20261122000000 extends AbstractMigration
{
    private const string TABLE = 'mcp_agent_report';

    public function getDescription(): string
    {
        return 'Create the journal of the background agents, mcp_agent_report, unless it exists.';
    }

    public function up(Schema $schema): void
    {
        // Not skipIf(): Doctrine Migrations does not record a skipped migration, it would stay pending.
        if ($schema->hasTable(self::TABLE)) {
            $this->write(\sprintf('  %s exists: nothing to create.', self::TABLE));

            return;
        }

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        $this->abortIf(true, \sprintf('%s supports MySQL, MariaDB and SQLite: create %s with a migration of your own (doctrine:schema:update --dump-sql).', self::class, self::TABLE));
    }

    /**
     * Keeps the table: up() may have found it created by the application's own migrations, and this migration
     * sorts after them, so `migrate prev` reaches it first. Dropping the journal is a decision, not a rollback.
     */
    public function down(Schema $schema): void
    {
        $this->write(\sprintf('  %s kept, with its reports: drop it by hand if you mean to.', self::TABLE));
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mcp_agent_report (
                id BIGINT AUTO_INCREMENT NOT NULL,
                notified_at DATETIME DEFAULT NULL,
                agent VARCHAR(64) NOT NULL,
                source VARCHAR(64) NOT NULL,
                source_sequence BIGINT NOT NULL,
                subject_type VARCHAR(32) NOT NULL,
                subject_id VARCHAR(64) NOT NULL,
                level VARCHAR(16) NOT NULL,
                signals JSON NOT NULL,
                explanation LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_mcp_agent_report_agent (agent, created_at),
                UNIQUE INDEX uniq_mcp_agent_report_source (agent, source),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mcp_agent_report (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                notified_at DATETIME DEFAULT NULL,
                agent VARCHAR(64) NOT NULL,
                source VARCHAR(64) NOT NULL,
                source_sequence BIGINT NOT NULL,
                subject_type VARCHAR(32) NOT NULL,
                subject_id VARCHAR(64) NOT NULL,
                level VARCHAR(16) NOT NULL,
                signals CLOB NOT NULL,
                explanation CLOB NOT NULL,
                created_at DATETIME NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_mcp_agent_report_agent ON mcp_agent_report (agent, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_agent_report_source ON mcp_agent_report (agent, source)');
    }
}
