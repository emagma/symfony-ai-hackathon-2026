<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the actor column to the MCP audit table.
 *
 * Until now an entry recorded what changed and which MCP session asked, but a
 * session id is a UUID the transport minted: it groups a caller's actions
 * together and names nobody. The one question an audit trail exists to answer
 * was unanswerable.
 *
 * Nullable, and existing rows stay null on purpose. Back-filling them with the
 * service account would be inventing a fact - those writes really were made
 * without a recorded identity, and the table should say so.
 */
final class Version20260907190000 extends AbstractMigration
{
    private const string TABLE = 'emagma_sylius_assistant_mcp_audit';

    public function getDescription(): string
    {
        return 'Add the actor column to the MCP audit table.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->hasTable(self::TABLE),
            sprintf('%s does not exist yet - the create migration already includes actor.', self::TABLE),
        );
        $this->skipIf(
            $schema->getTable(self::TABLE)->hasColumn('actor'),
            'actor column already present.',
        );

        $this->addSql(sprintf('ALTER TABLE %s ADD actor VARCHAR(255) DEFAULT NULL', self::TABLE));
        // Auditors ask "everything this person did" far more often than they
        // ask about one row, and the column is null on every pre-existing entry.
        $this->addSql(sprintf('CREATE INDEX IDX_emagma_mcp_audit_actor ON %s (actor)', self::TABLE));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE));

        $this->addSql(sprintf('DROP INDEX IDX_emagma_mcp_audit_actor ON %s', self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s DROP actor', self::TABLE));
    }
}
