<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Journal;

use Mcp\Server\RequestContext;

/**
 * Records nothing: the default until a journal, such as the audit bundle's, aliases WriteJournalInterface.
 */
final class NullWriteJournal implements WriteJournalInterface
{
    public function record(string $tool, string $targetType, string $targetCode, array $beforeState, array $afterState, array $diff, ?RequestContext $context = null, ?string $batchId = null): void
    {
    }

    public function stage(string $tool, string $targetType, string $targetCode, array $beforeState, array $afterState, array $diff, ?RequestContext $context = null, ?string $batchId = null): void
    {
    }

    public function flush(): void
    {
    }
}
