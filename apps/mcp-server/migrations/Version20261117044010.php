<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117044010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The jobs bundle is replaced by the tasks bundle: mcp_job and mcp_job_item are dropped.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE mcp_job');
        $this->addSql('DROP TABLE mcp_job_item');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mcp_job (started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, done_chunks INT NOT NULL, error LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, type VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, owner VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, parameters JSON NOT NULL, total_chunks INT NOT NULL, created_at DATETIME NOT NULL, INDEX idx_mcp_job_owner (owner, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE mcp_job_item (id BIGINT AUTO_INCREMENT NOT NULL, status VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, proposal JSON DEFAULT NULL, error LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, job_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, chunk INT NOT NULL, item_key VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, INDEX idx_mcp_job_item_chunk (job_id, chunk), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
    }
}
