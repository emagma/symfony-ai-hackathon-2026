<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Revert;

use Emagma\McpAuditBundle\Entity\AuditEntry;

/**
 * One entry ready to be written back: where, what it is now, what it becomes.
 *
 * @internal
 */
final readonly class RevertPlan
{
    /**
     * @param array<string, mixed>                               $current
     * @param array<string, mixed>                               $target
     * @param array<string, array{before: mixed, after: mixed}> $diff
     */
    public function __construct(
        public ?int $id,
        public AuditEntry $entry,
        public string $path,
        public array $current,
        public array $target,
        public array $diff,
    ) {
    }
}
