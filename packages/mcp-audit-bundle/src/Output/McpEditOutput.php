<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Output;

use Emagma\McpAuditBundle\DiffSummarizer;
use Emagma\McpAuditBundle\Entity\AuditEntry;

/**
 * The summary fields are repeated, not nested, and `found` comes first: the wire format is flat, in this order.
 *
 * @internal
 */
final readonly class McpEditOutput
{
    /**
     * @param array<string, mixed>      $diff
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        public bool $found,
        public ?int $id,
        public string $createdAt,
        public string $tool,
        public string $targetType,
        public string $targetCode,
        public string $summary,
        public bool $reverted,
        public ?int $revertsId,
        public ?string $sessionId,
        public ?string $batchId,
        public ?int $revertedById,
        public array $diff,
        public ?array $before,
        public ?array $after,
    ) {
    }

    public static function fromEntry(AuditEntry $entry): self
    {
        return new self(
            found: true,
            id: $entry->getId(),
            createdAt: $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            tool: $entry->getTool(),
            targetType: $entry->getTargetType(),
            targetCode: $entry->getTargetCode(),
            summary: DiffSummarizer::summarize($entry->getDiff()),
            reverted: null !== $entry->getRevertedBy(),
            revertsId: $entry->getReverts()?->getId(),
            sessionId: $entry->getSessionId(),
            batchId: $entry->getBatchId(),
            revertedById: $entry->getRevertedBy()?->getId(),
            diff: $entry->getDiff(),
            before: $entry->getBeforeState(),
            after: $entry->getAfterState(),
        );
    }
}
