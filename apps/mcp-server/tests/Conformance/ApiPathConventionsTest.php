<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpSyliusBundle\Testing\Conformance\ApiPathConventionsTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ApiPathConventionsTest extends ApiPathConventionsTestCase
{
    protected static function tools(): ToolSources
    {
        return ServerSources::tools();
    }
}
