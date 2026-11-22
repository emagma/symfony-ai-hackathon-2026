<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Emagma\McpCoreBundle\Redaction\SensitiveKeysProviderInterface;

/**
 * Scrubs the audit rows written before the redactor existed.
 *
 * `AuditWriter` now runs every snapshot through
 * {@see SensitiveDataRedactor}, but that only protects what is written from now
 * on. Any shop that has been using the write tools already has rows holding
 * gateway credentials and customer personal data in clear - the leak this
 * closes is historical, and it does not expire on its own.
 *
 * Run in PHP rather than SQL on purpose: the columns are JSON documents whose
 * shape varies per tool, and deciding what is sensitive means walking them with
 * the same rules the writer applies. Reusing the redactor is also what keeps
 * the two from drifting apart.
 *
 * Rows are rewritten in batches so a large history does not build one giant
 * transaction, and each row is only written back when redaction actually
 * changed something.
 *
 * This is deliberately irreversible: `down()` cannot invent data that was
 * destroyed on purpose, and pretending otherwise would be worse than saying so.
 *
 * Doctrine will report "was executed but did not result in any SQL statements"
 * - that warning counts `addSql()` calls, and the rewrite runs through the
 * connection directly because each row's new content depends on reading the old
 * one. The line this migration prints says what it actually did.
 */
final class Version20260907120000 extends AbstractMigration
{
    private const string TABLE = 'emagma_sylius_assistant_mcp_audit';

    private const int BATCH = 200;

    /**
     * The rules of the redactor as it was when this migration was written
     * (`Emagma\SyliusMcpServer\Audit\SensitiveDataRedactor`): its marker and
     * its key lists, frozen here so that the result never depends on what the
     * redactor, its providers or the host configuration became since.
     */
    private const string MARKER = '__REDACTED_BY_SYLIUS_ASSISTANT__';

    /** @var list<string> */
    private const array PERSONAL_KEYS = [
        'email', 'emailcanonical', 'firstname', 'lastname', 'fullname',
        'phonenumber', 'birthday', 'gender', 'street', 'postcode', 'city',
        'company', 'provincename', 'customerip', 'notes',
        'username', 'usernamecanonical',
    ];

    /** @var array<string, list<string>> */
    private const array OPAQUE_SUBTREES = [
        'gatewayconfig' => ['config'],
    ];

    public function getDescription(): string
    {
        return 'Redact secrets and personal data from MCP audit rows written before the redactor shipped.';
    }

    public function up(Schema $schema): void
    {
        // Nothing to do on a fresh install, and skipping keeps this harmless
        // when the table has not been created yet in some exotic order.
        $this->skipIf(
            !$schema->hasTable(self::TABLE),
            sprintf('%s does not exist - nothing to redact.', self::TABLE),
        );
    }

    /**
     * The rewrite runs post-up so it executes against committed schema and
     * outside the DDL transaction some platforms impose.
     */
    public function postUp(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $redactor = new SensitiveDataRedactor(
            self::PERSONAL_KEYS,
            providers: [new class(self::OPAQUE_SUBTREES) implements SensitiveKeysProviderInterface {
                /**
                 * @param array<string, list<string>> $opaqueSubtrees
                 */
                public function __construct(
                    private readonly array $opaqueSubtrees,
                ) {
                }

                public function personalKeys(): array
                {
                    return [];
                }

                public function opaqueSubtrees(): array
                {
                    return $this->opaqueSubtrees;
                }
            }],
            marker: self::MARKER,
        );
        $lastId = 0;
        $scanned = 0;
        $rewritten = 0;

        while (true) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT id, before_state, after_state, diff FROM %s WHERE id > :lastId ORDER BY id ASC LIMIT %d',
                    self::TABLE,
                    self::BATCH,
                ),
                ['lastId' => $lastId],
            );

            if ([] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                ++$scanned;

                $before = self::decode($row['before_state']);
                $after = self::decode($row['after_state']);
                $diff = self::decode($row['diff']);

                $redacted = [
                    'before_state' => $redactor->redact($before),
                    'after_state' => $redactor->redact($after),
                    'diff' => $redactor->redactDiff($diff),
                ];

                if ($redacted['before_state'] === $before &&
                    $redacted['after_state'] === $after &&
                    $redacted['diff'] === $diff
                ) {
                    continue;
                }

                $this->connection->update(
                    self::TABLE,
                    array_map(self::encode(...), $redacted),
                    ['id' => $lastId],
                );
                ++$rewritten;
            }
        }

        $this->write(sprintf('  scanned %d audit rows, redacted %d.', $scanned, $rewritten));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Redaction destroys the values on purpose; the previous contents cannot be restored.',
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, associative: true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function encode(array $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
