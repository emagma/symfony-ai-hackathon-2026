<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpSyliusBundle\Testing\Conformance\ToolResultEnvelopeTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ToolResultEnvelopeTest extends ToolResultEnvelopeTestCase
{
    protected static function tools(): ToolSources
    {
        return ServerSources::tools();
    }

    protected static function minimumChecked(): int
    {
        return 50;
    }
}
