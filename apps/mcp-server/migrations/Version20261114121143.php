<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114121143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The long-running jobs of the MCP server (mcp_job, mcp_job_item) and the Messenger table their queue lives in.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mcp_job (started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(32) NOT NULL, done_chunks INT NOT NULL, error LONGTEXT DEFAULT NULL, id VARCHAR(36) NOT NULL, type VARCHAR(64) NOT NULL, owner VARCHAR(255) DEFAULT NULL, parameters JSON NOT NULL, total_chunks INT NOT NULL, created_at DATETIME NOT NULL, INDEX idx_mcp_job_owner (owner, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mcp_job_item (id BIGINT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, proposal JSON DEFAULT NULL, error LONGTEXT DEFAULT NULL, job_id VARCHAR(36) NOT NULL, chunk INT NOT NULL, item_key VARCHAR(255) NOT NULL, INDEX idx_mcp_job_item_chunk (job_id, chunk), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        // The consumers create it on first use (auto_setup): on a fresh database, mcp-agents-worker or mcp-scheduler
        // may start before the first migration.
        if ($schema->hasTable('messenger_messages')) {
            return;
        }
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mcp_job');
        $this->addSql('DROP TABLE mcp_job_item');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
