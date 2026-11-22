<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fills ToolClassMap from the `addTool()` calls McpPass put on the server's builder: the tools of this server and no
 * other, named as the SDK names them. Runs after McpPass.
 *
 * @internal
 */
final class ToolClassMapPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('emagma_mcp_core.tool_class_map') || !$container->hasParameter('emagma_mcp_core.server')) {
            return;
        }
        $builder = ServerBuilder::id($container);
        if (!$container->hasDefinition($builder)) {
            return;
        }

        $classes = [];
        foreach ($container->getDefinition($builder)->getMethodCalls() as $call) {
            if (!\is_array($call) || 'addTool' !== ($call[0] ?? null) || !\is_array($call[1] ?? null)) {
                continue;
            }
            $arguments = $call[1];
            $handler = $arguments[0] ?? null;
            if (!\is_array($handler) || !\is_string($handler[0] ?? null) || !class_exists($handler[0])) {
                continue;
            }
            $class = $handler[0];
            $function = \is_string($handler[1] ?? null) ? $handler[1] : '__invoke';
            $name = $arguments[1] ?? null;
            // The SDK's rule for a tool without a name (ElementMetadataResolver). A name keeps the `%%` McpPass
            // escaped it with: the container resolves it back the same way.
            $name = \is_string($name) ? $name : ('__invoke' === $function ? (new \ReflectionClass($class))->getShortName() : $function);
            $classes[$name] = $class;
        }
        ksort($classes);

        $container->getDefinition('emagma_mcp_core.tool_class_map')->setArgument(0, $classes);
    }
}
