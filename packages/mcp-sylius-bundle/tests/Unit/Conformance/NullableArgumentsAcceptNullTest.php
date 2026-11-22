<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\Testing\Conformance\NullableArgumentsAcceptNullTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class NullableArgumentsAcceptNullTest extends NullableArgumentsAcceptNullTestCase
{
    protected static function tools(): ToolSources
    {
        return SyliusSources::tools();
    }

    protected static function minimumChecked(): int
    {
        return 100;
    }
}
