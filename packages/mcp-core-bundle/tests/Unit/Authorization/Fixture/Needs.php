<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Authorization\Fixture;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Needs
{
    public function __construct(
        public string $what,
    ) {
    }
}
