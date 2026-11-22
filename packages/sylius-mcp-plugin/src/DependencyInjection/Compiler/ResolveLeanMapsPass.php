<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\DependencyInjection\Compiler;

use Emagma\SyliusMcpPlugin\DependencyInjection\EmagmaSyliusMcpExtension;
use Emagma\SyliusMcpPlugin\DependencyInjection\LeanMaps;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Resolves the `%parameter%` keys of the lean maps, then merges the entries that name the same class.
 *
 * @internal
 */
final class ResolveLeanMapsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(EmagmaSyliusMcpExtension::LEAN_CONTEXT_BUILDER_ID)) {
            $definition = $container->getDefinition(EmagmaSyliusMcpExtension::LEAN_CONTEXT_BUILDER_ID);
            /** @var array<string, string> $groups */
            $groups = $definition->getArgument(EmagmaSyliusMcpExtension::LEAN_CONTEXT_BUILDER_MAP_ARGUMENT);

            $resolved = [];
            foreach ($groups as $resourceClass => $group) {
                $resolved[] = [$this->resolveClass($container, $resourceClass) => $group];
            }

            $definition->replaceArgument(
                EmagmaSyliusMcpExtension::LEAN_CONTEXT_BUILDER_MAP_ARGUMENT,
                LeanMaps::mergeGroups(...$resolved),
            );
        }

        if ($container->hasDefinition(EmagmaSyliusMcpExtension::FILTER_METADATA_FACTORY_ID)) {
            $definition = $container->getDefinition(EmagmaSyliusMcpExtension::FILTER_METADATA_FACTORY_ID);
            /** @var array<string, array<string, list<string>>> $filters */
            $filters = $definition->getArgument(EmagmaSyliusMcpExtension::FILTER_METADATA_FACTORY_MAP_ARGUMENT);

            $resolved = [];
            foreach ($filters as $resourceClass => $byUriTemplate) {
                $resolved[] = [$this->resolveClass($container, $resourceClass) => $byUriTemplate];
            }

            $definition->replaceArgument(
                EmagmaSyliusMcpExtension::FILTER_METADATA_FACTORY_MAP_ARGUMENT,
                LeanMaps::mergeFilters(...$resolved),
            );
        }
    }

    private function resolveClass(ContainerBuilder $container, string $resourceClass): string
    {
        $resolved = $container->getParameterBag()->resolveValue($resourceClass);
        if (!\is_string($resolved) || '' === $resolved) {
            throw new InvalidArgumentException(sprintf(
                'The lean map key "%s" of "%s" must resolve to a class name.',
                $resourceClass,
                EmagmaSyliusMcpExtension::ALIAS,
            ));
        }

        return $resolved;
    }
}
