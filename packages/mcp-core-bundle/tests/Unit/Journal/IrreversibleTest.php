<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Journal;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Irreversible::class)]
final class IrreversibleTest extends TestCase
{
    public function testADeletionIsIrreversibleWithoutSayingSo(): void
    {
        $delete = new #[Permission(resource: 'thing', action: Action::Delete)] class {
        };

        self::assertTrue(Irreversible::declaredBy($delete::class));
    }

    public function testATransitionSaysSo(): void
    {
        $transition = new #[Permission(resource: 'order', action: Action::Write)] #[Irreversible] class {
        };
        $update = new #[Permission(resource: 'order', action: Action::Write)] class {
        };

        self::assertTrue(Irreversible::declaredBy($transition::class));
        self::assertFalse(Irreversible::declaredBy($update::class));
    }
}
