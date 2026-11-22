<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110210230 extends AbstractMigration
{
    private const string TABLE = 'emagma_sylius_assistant_mcp_audit';

    public function getDescription(): string
    {
        return 'Align the MCP audit table with its mapping: the two indexes of the revert link take the names Doctrine derives, and created_at loses its DBAL 3 type comment. No data changes.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->hasTable(self::TABLE),
            sprintf('%s does not exist yet - nothing to align.', self::TABLE),
        );
        $this->skipIf(
            !$schema->getTable(self::TABLE)->hasIndex('uniq_emagma_mcp_audit_reverted_by'),
            'The indexes already carry the names of the mapping.',
        );

        $this->addSql(sprintf('ALTER TABLE %s CHANGE created_at created_at DATETIME NOT NULL', self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX uniq_emagma_mcp_audit_reverted_by TO UNIQ_A256B6F3A8E1562', self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX fk_emagma_mcp_audit_reverts TO IDX_A256B6F31E41FBF8', self::TABLE));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->hasTable(self::TABLE) || !$schema->getTable(self::TABLE)->hasIndex('UNIQ_A256B6F3A8E1562'),
            'Nothing to rename back.',
        );

        $this->addSql(sprintf('ALTER TABLE %s CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'', self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX IDX_A256B6F31E41FBF8 TO FK_emagma_mcp_audit_reverts', self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX UNIQ_A256B6F3A8E1562 TO UNIQ_emagma_mcp_audit_reverted_by', self::TABLE));
    }
}
