<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * An enabled linter is installed: its binary is checked when the container is built, not at the first task.
 *
 * @internal
 */
final class LinterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('emagma_mcp_tasks.script_linter')) {
            return;
        }
        $path = $container->getParameterBag()->resolveValue($container->getDefinition('emagma_mcp_tasks.script_linter')->getArgument(0));
        if (!\is_string($path) || !is_file($path) || !is_executable($path)) {
            throw new InvalidArgumentException(\sprintf('emagma_mcp_tasks.linter is enabled but its path "%s" is not an executable file: install the linter or disable it.', \is_string($path) ? $path : get_debug_type($path)));
        }
    }
}
