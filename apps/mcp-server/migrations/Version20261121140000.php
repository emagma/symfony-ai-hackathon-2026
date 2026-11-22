<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The audit refactor: entries a revert cannot put back, the erasure of an entry, no email as a target.
 *
 * - `revertible`: false for the entries of a tool no write puts back. Backfilled from the tool names
 *   as they were when this migration was written - every deletion, and the transitions, files and
 *   emails now marked #[Irreversible] - frozen here so the result never depends on what the code became.
 * - `forgotten_at`, `forgotten_by_id`: the erasure of an entry and the entry that traces it.
 * - `target_code` of a customer or an administrator: their id, never their email. The id is read off
 *   `after_state`, then `before_state` (`id`, else the end of `@id`); a row without one gets the marker.
 *   Run in PHP, in postUp, like Version20260907120000: the value of each row depends on its JSON.
 *
 * Irreversible: down() drops the columns, the emails replaced are not put back.
 */
final class Version20261121140000 extends AbstractMigration
{
    private const string TABLE = 'mcp_audit';

    private const int BATCH = 200;

    /** `emagma_mcp_core.redaction.marker` of this stack, what the table already holds. */
    private const string MARKER = '__REDACTED_BY_SYLIUS_ASSISTANT__';

    /** The redactor's email pattern when this migration was written. */
    private const string EMAIL_PATTERN = '/[\w.!#$%&\'*+\/=?^`{|}~-]+@[\w-]+(?:\.[\w-]+)+/u';

    /** @var list<string> the tools marked #[Irreversible] on 2026-11-21, deletions aside */
    private const array IRREVERSIBLE_TOOLS = [
        'accept_product_review', 'archive_promotion', 'archive_shipping_method', 'cancel_order',
        'complete_payment', 'create_product_image', 'create_taxon_image', 'forget_audit_personal_data',
        'generate_promotion_coupons', 'refund_payment', 'reject_product_review', 'remove_province',
        'resend_order_confirmation_email', 'resend_shipment_confirmation_email', 'restore_promotion',
        'restore_shipping_method', 'ship_shipment', 'update_product_taxons', 'upload_cms_media',
    ];

    public function getDescription(): string
    {
        return 'The audit refactor: revertible, forgotten_at and forgotten_by_id on mcp_audit; customers and administrators targeted by id, never by email.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), sprintf('%s does not exist - nothing to change.', self::TABLE));

        $this->addSql(sprintf('ALTER TABLE %s ADD revertible TINYINT DEFAULT 1 NOT NULL, ADD forgotten_at DATETIME DEFAULT NULL, ADD forgotten_by_id BIGINT DEFAULT NULL', self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT FK_50CBB768A862C4F8 FOREIGN KEY (forgotten_by_id) REFERENCES %1$s (id) ON DELETE SET NULL', self::TABLE));
        $this->addSql(sprintf('CREATE INDEX IDX_50CBB768A862C4F8 ON %s (forgotten_by_id)', self::TABLE));
        $this->addSql(
            sprintf(
                "UPDATE %s SET revertible = 0 WHERE tool LIKE 'delete\\_%%' OR tool IN (%s)",
                self::TABLE,
                implode(', ', array_map(static fn (string $tool): string => "'" . $tool . "'", self::IRREVERSIBLE_TOOLS)),
            ),
        );
    }

    public function postUp(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $lastId = 0;
        $rewritten = 0;
        while (true) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                sprintf(
                    "SELECT id, target_code, before_state, after_state FROM %s WHERE id > :lastId AND target_type IN ('customer', 'administrator') AND target_code LIKE '%%@%%' ORDER BY id ASC LIMIT %d",
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
                if (1 !== preg_match(self::EMAIL_PATTERN, (string) $row['target_code'])) {
                    continue;
                }
                $this->connection->update(self::TABLE, ['target_code' => self::idOf(self::decode($row['after_state']), self::decode($row['before_state']))], ['id' => $lastId]);
                ++$rewritten;
            }
        }

        $this->write(sprintf('  %d audit rows of a customer or an administrator now target an id, not an email.', $rewritten));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE));

        $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY FK_50CBB768A862C4F8', self::TABLE));
        $this->addSql(sprintf('DROP INDEX IDX_50CBB768A862C4F8 ON %s', self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s DROP revertible, DROP forgotten_at, DROP forgotten_by_id', self::TABLE));
        $this->write('  The target codes rewritten to ids stay ids: the emails are not put back.');
    }

    /**
     * @param array<array-key, mixed> ...$snapshots the latest first
     */
    private static function idOf(array ...$snapshots): string
    {
        foreach ($snapshots as $snapshot) {
            $id = $snapshot['id'] ?? null;
            if (\is_int($id) || (\is_string($id) && '' !== $id && self::MARKER !== $id)) {
                return (string) $id;
            }
        }
        foreach ($snapshots as $snapshot) {
            $iri = $snapshot['@id'] ?? null;
            if (\is_string($iri) && 1 === preg_match('#/(\d+)$#', $iri, $match)) {
                return $match[1];
            }
        }

        return self::MARKER;
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
}
