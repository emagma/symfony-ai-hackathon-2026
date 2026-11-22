<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit;

use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Tests\AuditTestTrait;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditScope::class)]
final class AuditScopeTest extends TestCase
{
    use AuditTestTrait;

    public function testAuditReachesTheCallersOwnEntriesAndAuditAllEveryEntry(): void
    {
        $own = $this->createAuditScope(['mcp:core:audit:write'], 'kc-ada');
        $all = $this->createAuditScope(['mcp:core:audit:read', 'mcp:core:audit_all:read'], 'kc-bob');

        self::assertSame('kc-ada', $own->actor(Action::Read));
        self::assertTrue($own->reaches($this->entry('kc-ada'), Action::Write));
        self::assertFalse($own->reaches($this->entry('kc-bob'), Action::Write));
        self::assertFalse($own->reaches($this->entry(null), Action::Read), 'an entry without actor is audit_all\'s');

        self::assertNull($all->actor(Action::Read));
        self::assertTrue($all->reaches($this->entry(null), Action::Read));
        self::assertSame('kc-bob', $all->actor(Action::Write), 'reading every entry is not reverting every entry');
    }

    public function testACallerWithoutIdentityReachesNothingButThroughAuditAll(): void
    {
        self::assertFalse($this->createAuditScope(['mcp:core:audit:read'])->actor(Action::Read));
        self::assertFalse($this->createAuditScope(['mcp:core:audit:read'])->reaches($this->entry(null), Action::Read));
        self::assertNull($this->createAuditScope(['mcp:core:audit_all:delete'])->actor(Action::Read), 'delete implies read');
    }

    public function testARevertNeedsTheGrantsOfTheToolThatRecordedTheEntry(): void
    {
        $tool = new #[Permission(resource: 'order', action: Action::Write)] class {
        };
        $tools = ['cancel_order' => $tool::class];
        $domains = ['class@anonymous' => 'sylius'];

        self::assertTrue($this->createAuditScope(['mcp:sylius:order:write'], 'a', $tools, $domains)->mayCall('cancel_order'));
        self::assertFalse($this->createAuditScope(['mcp:sylius:order:read'], 'a', $tools, $domains)->mayCall('cancel_order'));
        self::assertFalse($this->createAuditScope(['mcp:all:all:write'], 'a', $tools, $domains)->mayCall('update_taxon'), 'a name the server no longer exposes is refused');
        self::assertTrue($this->createAuditScope(null, 'a', $tools, $domains)->mayCall('update_taxon'), 'but for an explicit allow-all');
    }

    private function entry(?string $actor): AuditEntry
    {
        return new AuditEntry('upsert_taxons', 'taxon', 'mugs', ['name' => 'a'], [], [], actor: $actor);
    }
}
