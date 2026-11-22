<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\ToolAnnotationsCoverageTestCase;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ToolAnnotationsCoverageTest extends ToolAnnotationsCoverageTestCase
{
    protected static function toolClasses(): iterable
    {
        return ServerSources::tools()->classes();
    }

    protected static function minimumChecked(): int
    {
        return 50;
    }
}
