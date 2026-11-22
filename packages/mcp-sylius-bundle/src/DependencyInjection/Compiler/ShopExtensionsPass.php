<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\DependencyInjection\Compiler;

use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensions;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Tells {@see ShopExtensions} the names the installed bundles declare (tag `emagma_mcp_sylius.shop_extension`).
 *
 * @internal
 */
final class ShopExtensionsPass implements CompilerPassInterface
{
    private const string SERVICE = 'emagma_mcp_sylius.shop_extensions';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::SERVICE)) {
            return;
        }

        $known = [];
        foreach ($container->findTaggedServiceIds('emagma_mcp_sylius.shop_extension') as $id => $tags) {
            foreach ($tags as $tag) {
                $name = \is_array($tag) ? ($tag['extension'] ?? null) : null;
                if (!\is_string($name) || '' === $name) {
                    throw new InvalidArgumentException(\sprintf('The tag "emagma_mcp_sylius.shop_extension" of the service "%s" must name the extension in its "extension" attribute.', $id));
                }
                $known[$name] = $name;
            }
        }

        $container->getDefinition(self::SERVICE)->replaceArgument(1, array_values($known));
    }
}
