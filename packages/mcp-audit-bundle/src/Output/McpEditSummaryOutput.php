<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Output;

use Emagma\McpAuditBundle\DiffSummarizer;
use Emagma\McpAuditBundle\Entity\AuditEntry;

/**
 * The lean shape of an entry, for a listing: no snapshot, a one-line summary of the diff.
 *
 * @internal
 */
final readonly class McpEditSummaryOutput
{
    public function __construct(
        public ?int $id,
        public string $createdAt,
        public string $tool,
        public string $targetType,
        public string $targetCode,
        public string $summary,
        public bool $reverted,
        public ?int $revertsId,
    ) {
    }

    public static function fromEntry(AuditEntry $entry): self
    {
        return new self(
            id: $entry->getId(),
            createdAt: $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            tool: $entry->getTool(),
            targetType: $entry->getTargetType(),
            targetCode: $entry->getTargetCode(),
            summary: DiffSummarizer::summarize($entry->getDiff()),
            reverted: null !== $entry->getRevertedBy(),
            revertsId: $entry->getReverts()?->getId(),
        );
    }
}
