<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * A deprecated tool is hidden from `tools/list` and still served by `tools/call`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Deprecated
{
    public function __construct(
        public string $replacedBy,
    ) {
    }
}
