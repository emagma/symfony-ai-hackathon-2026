<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * A host configures nothing here: its only knob is the environment (the service
 * account credentials). The configuration tree is for OTHER bundles - an MCP
 * extension's shop half prepends its lean groups, its lean filters and its
 * extension name (see Configuration).
 */
final class EmagmaSyliusMcpExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    public const string ALIAS = Configuration::ALIAS;

    public const string LEAN_CONTEXT_BUILDER_ID = 'emagma_sylius_mcp.api.lean_context_builder';

    public const int LEAN_CONTEXT_BUILDER_MAP_ARGUMENT = 2;

    public const string FILTER_METADATA_FACTORY_ID = 'emagma_sylius_mcp.api.filter.metadata_factory';

    public const int FILTER_METADATA_FACTORY_MAP_ARGUMENT = 1;

    public const string CAPABILITIES_PROVIDER_ID = 'emagma_sylius_mcp.api.capabilities_provider';

    public const int CAPABILITIES_PROVIDER_EXTENSIONS_ARGUMENT = 0;

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        (new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config')))->load('services.php');

        /** @var array{lean: array{groups: array<string, string>, filters: array<string, array<string, list<string>>>}, extensions: array<string, string>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        // The core entries first, whatever the bundle order: the group map is
        // matched in order, and the core filters keep their place on the
        // operation. The `%parameter%` keys are resolved (and merged again) by
        // ResolveLeanMapsPass.
        $container->getDefinition(self::LEAN_CONTEXT_BUILDER_ID)->replaceArgument(
            self::LEAN_CONTEXT_BUILDER_MAP_ARGUMENT,
            LeanMaps::mergeGroups(Configuration::CORE_LEAN_GROUPS, $config['lean']['groups']),
        );
        $container->getDefinition(self::FILTER_METADATA_FACTORY_ID)->replaceArgument(
            self::FILTER_METADATA_FACTORY_MAP_ARGUMENT,
            LeanMaps::mergeFilters(Configuration::CORE_LEAN_FILTERS, $config['lean']['filters']),
        );
        $container->getDefinition(self::CAPABILITIES_PROVIDER_ID)->replaceArgument(
            self::CAPABILITIES_PROVIDER_EXTENSIONS_ARGUMENT,
            $config['extensions'],
        );
    }

    public function prepend(ContainerBuilder $container): void
    {
        // The `mcp:*:lean` serializer groups (config/serializer/),
        // merged additively into the shop's mapping - no existing group is
        // overridden, the lean ones register alongside. Same pattern Sylius
        // itself uses for its bundle mappings.
        $container->prependExtensionConfig('framework', [
            'serializer' => [
                'mapping' => [
                    'paths' => [__DIR__ . '/../../config/serializer'],
                ],
            ],
        ]);

        // The plugin's own API resource (the capabilities endpoint). The
        // directory holds nothing else: API Platform scans every class in it.
        $container->prependExtensionConfig('api_platform', [
            'mapping' => [
                'paths' => [__DIR__ . '/../ApiResource'],
            ],
        ]);
    }
}
