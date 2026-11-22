<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Journal;

use Mcp\Server\RequestContext;

/**
 * Extension point: where a write tool records what it changed.
 * Called after the backend has changed, so an implementation never throws.
 */
interface WriteJournalInterface
{
    /**
     * Records one write. `$targetType` says what was written (`product`, `customer`) and `$targetCode` its identifier,
     * never personal data: it is recorded as given. `$batchId` ties the writes of one batch call together.
     *
     * @param array<string, mixed> $beforeState the resource as read before the write, empty for a creation
     * @param array<string, mixed> $afterState  the resource as the backend returned it, empty for a deletion
     * @param array<string, mixed> $diff        field => `{before, after}`
     */
    public function record(string $tool, string $targetType, string $targetCode, array $beforeState, array $afterState, array $diff, ?RequestContext $context = null, ?string $batchId = null): void;

    /**
     * Prepares one write of a batch: flush() keeps them all at once.
     *
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     * @param array<string, mixed> $diff
     */
    public function stage(string $tool, string $targetType, string $targetCode, array $beforeState, array $afterState, array $diff, ?RequestContext $context = null, ?string $batchId = null): void;

    public function flush(): void;
}
