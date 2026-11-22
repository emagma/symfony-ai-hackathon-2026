<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

/**
 * @internal
 */
final readonly class MissingReference
{
    public function __construct(
        public string $resource,
        public string $code,
    ) {
    }
}
