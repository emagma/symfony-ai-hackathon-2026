<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\IrreversibleToolsTestCase;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class IrreversibleToolsTest extends IrreversibleToolsTestCase
{
    protected static function toolClasses(): iterable
    {
        return ServerSources::tools()->classes();
    }

    /** The verbs of the Sylius bundle, tested in its own suite. */
    protected static function transitionVerbs(): array
    {
        return ['cancel', 'complete', 'refund', 'ship', 'accept', 'reject', 'archive', 'restore', 'resend', 'generate', 'create', 'upload'];
    }

    protected static function minimumChecked(): int
    {
        return 40;
    }
}
