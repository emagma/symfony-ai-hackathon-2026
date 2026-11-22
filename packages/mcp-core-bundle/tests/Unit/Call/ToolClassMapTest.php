<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Call;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Journal\Irreversible;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolClassMap::class)]
final class ToolClassMapTest extends TestCase
{
    public function testItNamesTheClassOfATool(): void
    {
        $cancel = new #[Permission(resource: 'order', action: Action::Write)] #[Irreversible] class {
        };
        $rename = new #[Permission(resource: 'order', action: Action::Write)] class {
        };
        $map = new ToolClassMap(['cancel_order' => $cancel::class, 'rename_order' => $rename::class]);

        self::assertSame($cancel::class, $map->classOf('cancel_order'));
        self::assertTrue($map->isIrreversible('cancel_order'));
        self::assertFalse($map->isIrreversible('rename_order'));
    }

    public function testANameTheServerDoesNotExposeIsUnknownAndNotIrreversible(): void
    {
        $map = new ToolClassMap();

        self::assertNull($map->classOf('update_taxon'));
        self::assertFalse($map->isIrreversible('update_taxon'));
    }
}
