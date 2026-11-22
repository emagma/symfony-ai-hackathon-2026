<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Copies the SDK's tool locator to the tools/call chain and to LocalToolCaller: without it the tools are built with `new`, not fetched.
 * Runs after McpPass.
 *
 * @internal
 */
final class ToolCallLocatorPass implements CompilerPassInterface
{
    private const int LOCATOR_ARGUMENT = 2;

    public function process(ContainerBuilder $container): void
    {
        $builder = ServerBuilder::id($container);
        if (!$container->hasDefinition($builder) || !$container->hasDefinition('emagma_mcp_core.tool_call_handler')) {
            return;
        }

        foreach ($container->getDefinition($builder)->getMethodCalls() as [$method, $arguments]) {
            if ('setContainer' !== $method) {
                continue;
            }

            $locator = $arguments[0];
            $container->getDefinition('emagma_mcp_core.tool_call_handler')->replaceArgument(self::LOCATOR_ARGUMENT, $locator);
            if ($container->hasDefinition('emagma_mcp_core.local_tool_caller')) {
                $container->getDefinition('emagma_mcp_core.local_tool_caller')->replaceArgument(1, $locator);
            }

            return;
        }
    }
}
