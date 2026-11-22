<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\ToolAnnotationsCoverageTestCase;
use Emagma\McpSyliusBundle\SyliusSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ToolAnnotationsCoverageTest extends ToolAnnotationsCoverageTestCase
{
    protected static function toolClasses(): iterable
    {
        return SyliusSources::tools()->classes();
    }

    protected static function minimumChecked(): int
    {
        return 50;
    }
}
