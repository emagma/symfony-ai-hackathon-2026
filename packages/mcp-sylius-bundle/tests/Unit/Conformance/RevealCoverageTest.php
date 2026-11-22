<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\Testing\Conformance\RevealCoverageTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class RevealCoverageTest extends RevealCoverageTestCase
{
    protected static function tools(): ToolSources
    {
        return SyliusSources::tools();
    }

    protected static function outputs(): OutputCatalog
    {
        return SyliusSources::outputs();
    }

    protected static function knownDirs(): array
    {
        return ['Order'];
    }
}
