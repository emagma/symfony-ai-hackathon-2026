<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Authorization;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\Permission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Grants::class)]
final class GrantsTest extends TestCase
{
    public function testExactGrantAllowsItsPermission(): void
    {
        $grants = Grants::fromStrings(['mcp:sylius:product:read']);

        self::assertTrue($grants->allows(new Permission('product', Action::Read, 'sylius')));
        self::assertFalse($grants->allows(new Permission('taxon', Action::Read, 'sylius')));
    }

    public function testWriteImpliesReadOnTheSameResource(): void
    {
        $grants = Grants::fromStrings(['mcp:sylius:product:write']);

        self::assertTrue($grants->allows(new Permission('product', Action::Write, 'sylius')));
        self::assertTrue($grants->allows(new Permission('product', Action::Read, 'sylius')));
        self::assertFalse($grants->allows(new Permission('product', Action::Delete, 'sylius')));
    }

    public function testDeleteImpliesReadButNotWrite(): void
    {
        $grants = Grants::fromStrings(['mcp:sylius:product:delete']);

        self::assertTrue($grants->allows(new Permission('product', Action::Delete, 'sylius')));
        self::assertTrue($grants->allows(new Permission('product', Action::Read, 'sylius')));
        self::assertFalse($grants->allows(new Permission('product', Action::Write, 'sylius')));
    }

    public function testReadGrantDoesNotEscalate(): void
    {
        $grants = Grants::fromStrings(['mcp:sylius:product:read']);

        self::assertFalse($grants->allows(new Permission('product', Action::Write, 'sylius')));
        self::assertFalse($grants->allows(new Permission('product', Action::Delete, 'sylius')));
    }

    public function testAGrantOfOneDomainSaysNothingOfAnother(): void
    {
        $grants = Grants::fromStrings(['mcp:sylius:product:write', 'mcp:sylius_cms:all:read']);

        self::assertTrue($grants->allows(new Permission('product', Action::Write, 'sylius')));
        self::assertFalse($grants->allows(new Permission('product', Action::Write, 'tartampion')), 'the same resource name in another solution');
        self::assertTrue($grants->allows(new Permission('page', Action::Read, 'sylius_cms')), 'the wildcard of a domain covers its resources');
        self::assertFalse($grants->allows(new Permission('order', Action::Read, 'sylius')), 'and only its own');
    }

    public function testAPermissionWithoutDomainMatchesNoGrant(): void
    {
        $grants = Grants::fromStrings(['mcp:all:all:read', 'mcp:sylius:product:read']);

        self::assertFalse($grants->allows(new Permission('product', Action::Read)));
        self::assertTrue(Grants::allowAll()->allows(new Permission('product', Action::Read)));
    }

    public function testOldUnqualifiedGrantsAndCrossDomainResourceWildcardsAreIgnored(): void
    {
        $grants = Grants::fromStrings(['mcp:product:read', 'mcp:all:product:read']);

        self::assertFalse($grants->allows(new Permission('product', Action::Read, 'sylius')));
    }

    public function testRevealIsImpliedByNothing(): void
    {
        $reveal = new Permission('pii', Action::Reveal, 'core');

        self::assertFalse(Grants::fromStrings(['mcp:all:all:read', 'mcp:all:all:write', 'mcp:all:all:delete', 'mcp:core:pii:read'])->allows($reveal));
        self::assertTrue(Grants::fromStrings(['mcp:core:pii:reveal'])->allows($reveal));
        self::assertTrue(Grants::fromStrings(['mcp:all:all:reveal'])->allows($reveal));
        self::assertFalse(Grants::fromStrings(['mcp:core:pii:reveal'])->allows(new Permission('customer', Action::Read, 'sylius')), 'nor does it imply a read');
    }

    public function testWildcardResourceCoversEveryResourceWithImplications(): void
    {
        $grants = Grants::fromStrings(['mcp:all:all:write']);

        self::assertTrue($grants->allows(new Permission('product', Action::Write, 'sylius')));
        self::assertTrue($grants->allows(new Permission('zone', Action::Read, 'sylius')));
        self::assertFalse($grants->allows(new Permission('product', Action::Delete, 'sylius')));
    }

    public function testAllowsAllRequiresEveryPermission(): void
    {
        $grants = Grants::fromStrings(['mcp:sylius:product:write']);
        $both = [
            new Permission('product', Action::Write, 'sylius'),
            new Permission('taxon', Action::Read, 'sylius'),
        ];

        self::assertFalse($grants->allowsAll($both));
        self::assertTrue(Grants::fromStrings(['mcp:sylius:product:write', 'mcp:sylius:taxon:read'])->allowsAll($both));
    }

    public function testEmptyPermissionListIsNeverAllowedUnderRealGrants(): void
    {
        self::assertFalse(Grants::fromStrings(['mcp:all:all:write', 'mcp:all:all:read', 'mcp:all:all:delete'])->allowsAll([]));
        self::assertFalse(Grants::none()->allowsAll([]));
    }

    public function testAllowAllAllowsEverythingIncludingUndeclared(): void
    {
        $grants = Grants::allowAll();

        self::assertTrue($grants->isAllowAll());
        self::assertTrue($grants->allows(new Permission('anything', Action::Delete, 'sylius')));
        self::assertTrue($grants->allowsAll([]));
    }

    public function testMalformedGrantStringsAreIgnored(): void
    {
        $grants = Grants::fromStrings([
            'sylius-admin',
            'mcp:product',
            'mcp:product:admin',
            'mcp::read',
            'mcp:sylius:product:read:extra',
        ]);

        self::assertFalse($grants->allows(new Permission('product', Action::Read, 'sylius')));
    }

    public function testNoneAllowsNothing(): void
    {
        $grants = Grants::none();

        self::assertFalse($grants->isAllowAll());
        self::assertFalse($grants->allows(new Permission('product', Action::Read, 'sylius')));
    }
}
