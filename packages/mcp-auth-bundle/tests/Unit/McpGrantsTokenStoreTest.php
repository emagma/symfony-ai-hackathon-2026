<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\Tests\Unit;

use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\Permission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpGrantsTokenStore::class)]
final class McpGrantsTokenStoreTest extends TestCase
{
    public function testEmptyStoreDeniesEverything(): void
    {
        $store = new McpGrantsTokenStore();

        self::assertFalse($store->grants()->allows(new Permission('product', Action::Read, 'sylius')));
    }

    public function testStoredGrantsAreReturned(): void
    {
        $store = new McpGrantsTokenStore();
        $store->store(Grants::fromStrings(['mcp:sylius:product:read']));

        self::assertTrue($store->grants()->allows(new Permission('product', Action::Read, 'sylius')));
    }

    public function testResetDropsGrants(): void
    {
        $store = new McpGrantsTokenStore();
        $store->store(Grants::fromStrings(['mcp:sylius:product:read']));

        $store->reset();

        self::assertFalse($store->grants()->allows(new Permission('product', Action::Read, 'sylius')));
    }
}
