<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\Testing\Conformance\RevealCoverageTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class RevealCoverageTest extends RevealCoverageTestCase
{
    protected static function tools(): ToolSources
    {
        return ServerSources::tools();
    }

    protected static function outputs(): OutputCatalog
    {
        return ServerSources::outputs();
    }

    protected static function knownDirs(): array
    {
        return ['Order'];
    }
}
