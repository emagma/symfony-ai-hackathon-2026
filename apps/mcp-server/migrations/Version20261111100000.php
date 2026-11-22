<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111100000 extends AbstractMigration
{
    private const string FROM = 'emagma_sylius_assistant_mcp_audit';

    private const string TO = 'mcp_audit';

    public function getDescription(): string
    {
        return 'Rename the MCP audit table to the default of the audit bundle; the two indexes of the revert link follow the names Doctrine derives from it. No data changes.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::FROM), sprintf('%s does not exist - nothing to rename.', self::FROM));

        $this->addSql(sprintf('RENAME TABLE %s TO %s', self::FROM, self::TO));
        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX UNIQ_A256B6F3A8E1562 TO UNIQ_50CBB768A8E1562', self::TO));
        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX IDX_A256B6F31E41FBF8 TO IDX_50CBB7681E41FBF8', self::TO));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TO), sprintf('%s does not exist - nothing to rename back.', self::TO));

        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX IDX_50CBB7681E41FBF8 TO IDX_A256B6F31E41FBF8', self::TO));
        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX UNIQ_50CBB768A8E1562 TO UNIQ_A256B6F3A8E1562', self::TO));
        $this->addSql(sprintf('RENAME TABLE %s TO %s', self::TO, self::FROM));
    }
}
