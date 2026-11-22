<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the MCP audit table that backs the write-tool history + revert chain.
 */
final class Version20260612000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create emagma_sylius_assistant_mcp_audit (MCP write audit + revert chain).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE emagma_sylius_assistant_mcp_audit (
                id              BIGINT AUTO_INCREMENT NOT NULL,
                created_at      DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                tool            VARCHAR(64) NOT NULL,
                target_type     VARCHAR(32) NOT NULL,
                target_code     VARCHAR(255) NOT NULL,
                before_state    JSON NOT NULL,
                after_state     JSON NOT NULL,
                diff            JSON NOT NULL,
                session_id      VARCHAR(64) DEFAULT NULL,
                reverts_id      BIGINT DEFAULT NULL,
                reverted_by_id  BIGINT DEFAULT NULL,
                INDEX idx_target (target_type, target_code, created_at),
                INDEX idx_session (session_id, created_at),
                UNIQUE INDEX UNIQ_emagma_mcp_audit_reverted_by (reverted_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(
            'ALTER TABLE emagma_sylius_assistant_mcp_audit '
            . 'ADD CONSTRAINT FK_emagma_mcp_audit_reverts '
            . 'FOREIGN KEY (reverts_id) REFERENCES emagma_sylius_assistant_mcp_audit (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE emagma_sylius_assistant_mcp_audit '
            . 'ADD CONSTRAINT FK_emagma_mcp_audit_reverted_by '
            . 'FOREIGN KEY (reverted_by_id) REFERENCES emagma_sylius_assistant_mcp_audit (id) ON DELETE SET NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE emagma_sylius_assistant_mcp_audit');
    }
}
