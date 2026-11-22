<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpSyliusBundle\Testing\Conformance\NullableArgumentsAcceptNullTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class NullableArgumentsAcceptNullTest extends NullableArgumentsAcceptNullTestCase
{
    protected static function tools(): ToolSources
    {
        return ServerSources::tools();
    }

    protected static function minimumChecked(): int
    {
        return 100;
    }
}
