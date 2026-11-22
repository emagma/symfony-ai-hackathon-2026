<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\Testing\Conformance\BatchUpsertUntrustedDiffTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class BatchUpsertUntrustedDiffTest extends BatchUpsertUntrustedDiffTestCase
{
    protected static function tools(): ToolSources
    {
        return ServerSources::tools();
    }

    protected static function outputs(): OutputCatalog
    {
        return ServerSources::outputs();
    }

    protected static function minimumChecked(): int
    {
        return 20;
    }

    protected static function knownDirs(): array
    {
        return ['Customer', 'Administrator'];
    }
}
