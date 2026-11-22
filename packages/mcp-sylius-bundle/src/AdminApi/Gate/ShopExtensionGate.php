<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Gate;

use Emagma\McpCoreBundle\Authorization\HandlerClass;
use Emagma\McpCoreBundle\Authorization\ToolGateInterface;
use Emagma\McpCoreBundle\Authorization\ToolRefusal;
use Mcp\Capability\Registry\ToolReference;

/**
 * Hides a tool whose shop extension is not active, and refuses its call.
 *
 * @internal
 */
final readonly class ShopExtensionGate implements ToolGateInterface
{
    public function __construct(
        private ShopExtensions $shopExtensions,
    ) {
    }

    public function refusal(ToolReference $reference): ?ToolRefusal
    {
        $class = HandlerClass::of($reference->handler);
        $extension = null === $class ? null : self::requiredBy($class);
        if (null === $extension || $this->shopExtensions->isActive($extension)) {
            return null;
        }

        return new ToolRefusal(
            [
                'ok' => false,
                'reason' => 'shop_extension_disabled',
                'extension' => $extension,
                'hint' => \sprintf('This tool needs the shop extension "%s", which this MCP server does not activate (MCP_SHOP_EXTENSIONS). Tell the human; do not retry.', $extension),
            ],
            'MCP tool call on a tool whose shop extension is disabled.',
            ['extension' => $extension],
        );
    }

    /**
     * @param class-string $class
     */
    public static function requiredBy(string $class): ?string
    {
        // Safe as a static: the cache holds class names only, never caller state.
        /** @var array<class-string, string|false> $cache */
        static $cache = [];
        if (!\array_key_exists($class, $cache)) {
            $attributes = (new \ReflectionClass($class))->getAttributes(RequiresShopExtension::class);
            $cache[$class] = [] === $attributes ? false : $attributes[0]->newInstance()->name;
        }

        return false === $cache[$class] ? null : $cache[$class];
    }
}
