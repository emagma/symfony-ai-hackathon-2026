<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Testing;

/**
 * What a test mocks to see the writes a tool records.
 */
interface WriteSinkInterface
{
    public function persist(RecordedWrite $write): void;

    public function flush(): void;
}
