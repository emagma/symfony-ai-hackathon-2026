<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Repository;

use Emagma\McpAuditBundle\Entity\AuditEntry;

/**
 * The queries of the audit: what its tools and commands read and purge through.
 * An `$actor` restricts a query to the entries of that actor; null reaches every entry.
 */
interface AuditRepositoryInterface
{
    public function find(int $id): ?AuditEntry;

    /**
     * @param list<int> $ids
     *
     * @return list<AuditEntry>
     */
    public function findByIds(array $ids, ?string $actor = null): array;

    /**
     * The latest first: the order a batch is reverted in.
     *
     * @return list<AuditEntry>
     */
    public function findByBatch(string $batchId, ?string $actor = null): array;

    /**
     * The entries written on the subject, and those whose snapshots hold, at any depth,
     * a string ending with one of the suffixes.
     *
     * @param list<string> $referenceSuffixes how the backend's payloads point at the subject
     *
     * @return list<AuditEntry>
     */
    public function findConcerning(string $type, string $id, array $referenceSuffixes = []): array;

    /**
     * The latest first.
     *
     * @param array{
     *     targetType?: string,
     *     targetCode?: string,
     *     sessionId?: string,
     *     batchId?: string,
     *     since?: \DateTimeImmutable,
     *     includeReverted?: bool,
     *     actor?: string,
     * } $filters
     *
     * @return array{items: list<AuditEntry>, total: int}
     */
    public function paginate(array $filters, int $page, int $itemsPerPage): array;

    public function countBefore(\DateTimeImmutable $cutoff): int;

    /**
     * Deletes the entries created before the cutoff, in one query.
     * A younger entry that pointed at one of them keeps its row: both self-references are `ON DELETE SET NULL`.
     */
    public function purgeBefore(\DateTimeImmutable $cutoff): int;
}
