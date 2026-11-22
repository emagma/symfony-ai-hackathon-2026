<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116213650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Background tasks written on demand: mcp_task, mcp_task_call, mcp_task_authorization.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mcp_task (status VARCHAR(32) NOT NULL, current_step INT NOT NULL, claimed_step INT NOT NULL, calls INT NOT NULL, last_tool VARCHAR(128) DEFAULT NULL, last_call_at DATETIME DEFAULT NULL, error LONGTEXT DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, id VARCHAR(36) NOT NULL, owner VARCHAR(255) DEFAULT NULL, objective LONGTEXT NOT NULL, recap LONGTEXT NOT NULL, reading LONGTEXT NOT NULL, manifest JSON NOT NULL, steps JSON NOT NULL, created_at DATETIME NOT NULL, INDEX idx_mcp_task_owner (owner, created_at), INDEX idx_mcp_task_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mcp_task_authorization (owner VARCHAR(255) NOT NULL, token LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (owner)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mcp_task_call (id INT AUTO_INCREMENT NOT NULL, outcome LONGTEXT DEFAULT NULL, error LONGTEXT DEFAULT NULL, duration_ms INT DEFAULT NULL, task_id VARCHAR(36) NOT NULL, step INT NOT NULL, tool VARCHAR(128) NOT NULL, arguments JSON NOT NULL, started_at DATETIME NOT NULL, INDEX idx_mcp_task_call_task (task_id, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mcp_task');
        $this->addSql('DROP TABLE mcp_task_authorization');
        $this->addSql('DROP TABLE mcp_task_call');
    }
}
