<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015120000 extends AbstractMigration
{
    private const string TABLE = 'emagma_sylius_assistant_mcp_audit';

    public function getDescription(): string
    {
        return 'Add the batch_id column to the MCP audit table: every item of one batch tool call shares it.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->hasTable(self::TABLE),
            sprintf('%s does not exist yet - nothing to add a column to.', self::TABLE),
        );
        $this->skipIf(
            $schema->getTable(self::TABLE)->hasColumn('batch_id'),
            'batch_id column already present.',
        );

        $this->addSql(sprintf('ALTER TABLE %s ADD batch_id VARCHAR(36) DEFAULT NULL', self::TABLE));
        $this->addSql(sprintf('CREATE INDEX idx_batch ON %s (batch_id)', self::TABLE));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE));

        $this->addSql(sprintf('DROP INDEX idx_batch ON %s', self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s DROP batch_id', self::TABLE));
    }
}
