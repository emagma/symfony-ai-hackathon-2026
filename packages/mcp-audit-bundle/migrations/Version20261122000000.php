<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the audit table as the mapping describes it, unless it exists: an application that
 * created it with migrations of its own runs this one as a no-op, recorded as done.
 * MySQL, MariaDB and SQLite; on another platform, write the table's migration yourself
 * (`doctrine:schema:update --dump-sql` gives it).
 * It creates the default name, `mcp_audit`: another `emagma_mcp_audit.table` needs a migration of your own.
 */
final class Version20261122000000 extends AbstractMigration
{
    private const string TABLE = 'mcp_audit';

    public function getDescription(): string
    {
        return 'Create the MCP audit table, mcp_audit, unless it exists.';
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
     * Keeps the table: up() may have found it created by the application's own migrations, and this
     * migration sorts after them, so `migrate prev` reaches it first. Dropping the audit is a decision, not a rollback.
     */
    public function down(Schema $schema): void
    {
        $this->write(\sprintf('  %s kept, with its entries: drop it by hand if you mean to.', self::TABLE));
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mcp_audit (
                id BIGINT AUTO_INCREMENT NOT NULL,
                created_at DATETIME NOT NULL,
                tool VARCHAR(64) NOT NULL,
                target_type VARCHAR(32) NOT NULL,
                target_code VARCHAR(255) NOT NULL,
                before_state JSON NOT NULL,
                after_state JSON NOT NULL,
                diff JSON NOT NULL,
                session_id VARCHAR(64) DEFAULT NULL,
                batch_id VARCHAR(36) DEFAULT NULL,
                actor VARCHAR(255) DEFAULT NULL,
                revertible TINYINT DEFAULT 1 NOT NULL,
                forgotten_at DATETIME DEFAULT NULL,
                reverts_id BIGINT DEFAULT NULL,
                reverted_by_id BIGINT DEFAULT NULL,
                forgotten_by_id BIGINT DEFAULT NULL,
                UNIQUE INDEX UNIQ_50CBB768A8E1562 (reverted_by_id),
                INDEX IDX_emagma_mcp_audit_actor (actor),
                INDEX idx_target (target_type, target_code, created_at),
                INDEX idx_session (session_id, created_at),
                INDEX idx_batch (batch_id),
                INDEX IDX_50CBB7681E41FBF8 (reverts_id),
                INDEX IDX_50CBB768A862C4F8 (forgotten_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE mcp_audit ADD CONSTRAINT FK_50CBB768A8E1562 FOREIGN KEY (reverted_by_id) REFERENCES mcp_audit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mcp_audit ADD CONSTRAINT FK_50CBB7681E41FBF8 FOREIGN KEY (reverts_id) REFERENCES mcp_audit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mcp_audit ADD CONSTRAINT FK_50CBB768A862C4F8 FOREIGN KEY (forgotten_by_id) REFERENCES mcp_audit (id) ON DELETE SET NULL');
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mcp_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                created_at DATETIME NOT NULL,
                tool VARCHAR(64) NOT NULL,
                target_type VARCHAR(32) NOT NULL,
                target_code VARCHAR(255) NOT NULL,
                before_state CLOB NOT NULL,
                after_state CLOB NOT NULL,
                diff CLOB NOT NULL,
                session_id VARCHAR(64) DEFAULT NULL,
                batch_id VARCHAR(36) DEFAULT NULL,
                actor VARCHAR(255) DEFAULT NULL,
                revertible BOOLEAN DEFAULT 1 NOT NULL,
                forgotten_at DATETIME DEFAULT NULL,
                reverts_id BIGINT DEFAULT NULL,
                reverted_by_id BIGINT DEFAULT NULL,
                forgotten_by_id BIGINT DEFAULT NULL,
                CONSTRAINT FK_50CBB768A8E1562 FOREIGN KEY (reverted_by_id) REFERENCES mcp_audit (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_50CBB7681E41FBF8 FOREIGN KEY (reverts_id) REFERENCES mcp_audit (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_50CBB768A862C4F8 FOREIGN KEY (forgotten_by_id) REFERENCES mcp_audit (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_50CBB768A8E1562 ON mcp_audit (reverted_by_id)');
        $this->addSql('CREATE INDEX IDX_emagma_mcp_audit_actor ON mcp_audit (actor)');
        $this->addSql('CREATE INDEX idx_target ON mcp_audit (target_type, target_code, created_at)');
        $this->addSql('CREATE INDEX idx_session ON mcp_audit (session_id, created_at)');
        $this->addSql('CREATE INDEX idx_batch ON mcp_audit (batch_id)');
        $this->addSql('CREATE INDEX IDX_50CBB7681E41FBF8 ON mcp_audit (reverts_id)');
        $this->addSql('CREATE INDEX IDX_50CBB768A862C4F8 ON mcp_audit (forgotten_by_id)');
    }
}
