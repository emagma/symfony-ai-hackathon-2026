<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\Testing\Conformance\BatchUpsertUntrustedDiffTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class BatchUpsertUntrustedDiffTest extends BatchUpsertUntrustedDiffTestCase
{
    protected static function tools(): ToolSources
    {
        return SyliusSources::tools();
    }

    protected static function outputs(): OutputCatalog
    {
        return SyliusSources::outputs();
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
