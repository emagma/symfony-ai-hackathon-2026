<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\IrreversibleToolsTestCase;
use Emagma\McpSyliusBundle\SyliusSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class IrreversibleToolsTest extends IrreversibleToolsTestCase
{
    protected static function toolClasses(): iterable
    {
        return SyliusSources::tools()->classes();
    }

    /** The transitions of Sylius's state machines, the emails sent, and what is created. */
    protected static function transitionVerbs(): array
    {
        return ['cancel', 'complete', 'refund', 'ship', 'accept', 'reject', 'archive', 'restore', 'resend', 'generate', 'create'];
    }

    protected static function minimumChecked(): int
    {
        return 30;
    }
}
