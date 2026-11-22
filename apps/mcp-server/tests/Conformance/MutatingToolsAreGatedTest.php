<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\MutatingToolsAreGatedTestCase;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class MutatingToolsAreGatedTest extends MutatingToolsAreGatedTestCase
{
    protected static function toolClasses(): iterable
    {
        return ServerSources::tools()->classes();
    }

    protected static function minimumChecked(): int
    {
        return 20;
    }
}
