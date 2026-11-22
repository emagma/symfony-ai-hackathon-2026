<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the three tables of the tasks as the mapping describes them, each unless it exists: an
 * application that created them with migrations of its own runs this one as a no-op, recorded as done.
 * MySQL, MariaDB and SQLite; on another platform, write the tables' migration yourself
 * (`doctrine:schema:update --dump-sql` gives it).
 */
final class Version20261122000000 extends AbstractMigration
{
    private const array TABLES = ['mcp_task', 'mcp_task_authorization', 'mcp_task_call'];

    public function getDescription(): string
    {
        return 'Create the tables of the background tasks (mcp_task, mcp_task_authorization, mcp_task_call), unless they exist.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;
        $this->abortIf(!$mysql && !$sqlite, \sprintf('%s supports MySQL, MariaDB and SQLite: create the tables of the tasks with a migration of your own (doctrine:schema:update --dump-sql).', self::class));

        // Not skipIf(): Doctrine Migrations does not record a skipped migration, it would stay pending.
        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table)) {
                $this->write(\sprintf('  %s exists: nothing to create.', $table));

                continue;
            }

            foreach ($mysql ? $this->mySql($table) : $this->sqlite($table) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    /**
     * Keeps the tables: up() may have found them created by the application's own migrations, and this
     * migration sorts after them, so `migrate prev` reaches it first. Dropping the tasks is a decision, not a rollback.
     */
    public function down(Schema $schema): void
    {
        $this->write('  The tables of the tasks are kept, with their rows: drop them by hand if you mean to.');
    }

    /**
     * @return list<string>
     */
    private function mySql(string $table): array
    {
        $options = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB';

        return match ($table) {
            'mcp_task' => [
                'CREATE TABLE mcp_task (status VARCHAR(32) NOT NULL, current_step INT NOT NULL, claimed_step INT NOT NULL, step_in_flight TINYINT NOT NULL, calls INT NOT NULL, last_tool VARCHAR(128) DEFAULT NULL, last_call_at DATETIME DEFAULT NULL, error LONGTEXT DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, id VARCHAR(36) NOT NULL, owner VARCHAR(255) DEFAULT NULL, objective LONGTEXT NOT NULL, recap LONGTEXT NOT NULL, reading LONGTEXT NOT NULL, manifest JSON NOT NULL, steps JSON NOT NULL, created_at DATETIME NOT NULL, INDEX idx_mcp_task_owner (owner, created_at), INDEX idx_mcp_task_status (status), PRIMARY KEY (id)) ' . $options,
            ],
            'mcp_task_authorization' => [
                'CREATE TABLE mcp_task_authorization (owner VARCHAR(255) NOT NULL, token LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (owner)) ' . $options,
            ],
            default => [
                'CREATE TABLE mcp_task_call (id INT AUTO_INCREMENT NOT NULL, outcome LONGTEXT DEFAULT NULL, error LONGTEXT DEFAULT NULL, duration_ms INT DEFAULT NULL, task_id VARCHAR(36) NOT NULL, step INT NOT NULL, tool VARCHAR(128) NOT NULL, arguments JSON NOT NULL, started_at DATETIME NOT NULL, INDEX idx_mcp_task_call_task (task_id, id), PRIMARY KEY (id)) ' . $options,
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function sqlite(string $table): array
    {
        return match ($table) {
            'mcp_task' => [
                'CREATE TABLE mcp_task (status VARCHAR(32) NOT NULL, current_step INTEGER NOT NULL, claimed_step INTEGER NOT NULL, step_in_flight BOOLEAN NOT NULL, calls INTEGER NOT NULL, last_tool VARCHAR(128) DEFAULT NULL, last_call_at DATETIME DEFAULT NULL, error CLOB DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, id VARCHAR(36) NOT NULL, owner VARCHAR(255) DEFAULT NULL, objective CLOB NOT NULL, recap CLOB NOT NULL, reading CLOB NOT NULL, manifest CLOB NOT NULL, steps CLOB NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))',
                'CREATE INDEX idx_mcp_task_owner ON mcp_task (owner, created_at)',
                'CREATE INDEX idx_mcp_task_status ON mcp_task (status)',
            ],
            'mcp_task_authorization' => [
                'CREATE TABLE mcp_task_authorization (owner VARCHAR(255) NOT NULL, token CLOB NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (owner))',
            ],
            default => [
                'CREATE TABLE mcp_task_call (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, outcome CLOB DEFAULT NULL, error CLOB DEFAULT NULL, duration_ms INTEGER DEFAULT NULL, task_id VARCHAR(36) NOT NULL, step INTEGER NOT NULL, tool VARCHAR(128) NOT NULL, arguments CLOB NOT NULL, started_at DATETIME NOT NULL)',
                'CREATE INDEX idx_mcp_task_call_task ON mcp_task_call (task_id, id)',
            ],
        };
    }
}
