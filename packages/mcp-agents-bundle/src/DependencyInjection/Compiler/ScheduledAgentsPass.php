<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\DependencyInjection\Compiler;

use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The agents tagged `emagma_mcp_agents.agent`, by name, for the schedule and the runner. Two agents with the same
 * name would share a journal and a cursor: the container is not built.
 *
 * @internal
 */
final class ScheduledAgentsPass implements CompilerPassInterface
{
    public const string TAG = 'emagma_mcp_agents.agent';

    public function process(ContainerBuilder $container): void
    {
        $agents = [];
        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass() ?? $id);
            if (!\is_string($class) || !is_subclass_of($class, ScheduledAgentInterface::class)) {
                throw new InvalidArgumentException(\sprintf('The service "%s" is tagged "%s" but does not implement %s.', $id, self::TAG, ScheduledAgentInterface::class));
            }
            $name = $class::name();
            if (isset($agents[$name])) {
                throw new InvalidArgumentException(\sprintf('Two background agents are named "%s": "%s" and "%s".', $name, (string) $agents[$name], $id));
            }
            $agents[$name] = new Reference($id);
        }

        $locator = ServiceLocatorTagPass::register($container, $agents);
        foreach (['emagma_mcp_agents.schedule_provider', 'emagma_mcp_agents.runner'] as $service) {
            if ($container->hasDefinition($service)) {
                $container->getDefinition($service)->setArgument('$agents', $locator);
            }
        }
    }
}
