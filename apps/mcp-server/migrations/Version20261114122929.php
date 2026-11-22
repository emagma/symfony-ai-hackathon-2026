<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114122929 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What the shop tells the server (mcp_shop_event) and what the background agents found (mcp_agent_report).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mcp_agent_report (id BIGINT AUTO_INCREMENT NOT NULL, notified_at DATETIME DEFAULT NULL, agent VARCHAR(64) NOT NULL, source VARCHAR(64) NOT NULL, source_sequence BIGINT NOT NULL, subject_type VARCHAR(32) NOT NULL, subject_id VARCHAR(64) NOT NULL, level VARCHAR(16) NOT NULL, signals JSON NOT NULL, explanation LONGTEXT NOT NULL, created_at DATETIME NOT NULL, INDEX idx_mcp_agent_report_agent (agent, created_at), UNIQUE INDEX uniq_mcp_agent_report_source (agent, source), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mcp_shop_event (id BIGINT AUTO_INCREMENT NOT NULL, event_id VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL, subject_id VARCHAR(64) DEFAULT NULL, payload JSON NOT NULL, received_at DATETIME NOT NULL, INDEX idx_mcp_shop_event_name (name, id), UNIQUE INDEX uniq_mcp_shop_event_event_id (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mcp_agent_report');
        $this->addSql('DROP TABLE mcp_shop_event');
    }
}
