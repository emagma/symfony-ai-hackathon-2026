<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Gate;

use Emagma\McpSyliusBundle\AdminApi\Gate\RequiresShopExtension;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensionGate;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensions;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShopExtensionGate::class)]
final class ShopExtensionGateTest extends TestCase
{
    public function testAToolOfAnInactiveExtensionIsRefusedWithTheReasonTheModelReads(): void
    {
        $refusal = (new ShopExtensionGate(new ShopExtensions()))->refusal($this->cmsTool());

        self::assertNotNull($refusal);
        self::assertFalse($refusal->answer['ok']);
        self::assertSame('shop_extension_disabled', $refusal->answer['reason']);
        self::assertSame('sylius_cms', $refusal->answer['extension']);
        self::assertIsString($refusal->answer['hint']);
        self::assertStringContainsString('MCP_SHOP_EXTENSIONS', $refusal->answer['hint']);
        self::assertSame(['extension' => 'sylius_cms'], $refusal->logContext);
    }

    public function testAToolOfAnActiveExtensionPasses(): void
    {
        self::assertNull((new ShopExtensionGate(new ShopExtensions('sylius_cms', ['sylius_cms'])))->refusal($this->cmsTool()));
    }

    public function testAToolThatRequiresNoExtensionPassesOnAnyShop(): void
    {
        $gate = new ShopExtensionGate(new ShopExtensions());

        self::assertNull($gate->refusal($this->reference(new class() {
            public function __invoke(): void
            {
            }
        })));
        self::assertNull($gate->refusal(new ToolReference(new Tool('closure', null, ['type' => 'object'], null, null), static fn (): string => 'x')));
    }

    public function testTheRequiredExtensionIsReadableFromTheClassAlone(): void
    {
        $handler = $this->cmsTool()->handler;
        self::assertIsArray($handler);
        self::assertIsObject($handler[0]);

        self::assertSame('sylius_cms', ShopExtensionGate::requiredBy($handler[0]::class));
        self::assertNull(ShopExtensionGate::requiredBy(self::class));
    }

    private function cmsTool(): ToolReference
    {
        return $this->reference(new #[RequiresShopExtension('sylius_cms')] class {
            public function __invoke(): void
            {
            }
        });
    }

    private function reference(object $handler): ToolReference
    {
        return new ToolReference(new Tool('tool', null, ['type' => 'object'], null, null), [$handler, '__invoke']);
    }
}
