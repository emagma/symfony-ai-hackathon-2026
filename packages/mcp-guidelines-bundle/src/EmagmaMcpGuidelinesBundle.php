<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle;

use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Emagma\McpGuidelinesBundle\DependencyInjection\Compiler\GuidelinesPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Gives the model the guidelines of the business before it writes: declared as resources and intents,
 * resolved and checked when the container is built.
 */
final class EmagmaMcpGuidelinesBundle extends AbstractBundle
{
    /** Tag of the services that read a resource (`GuidelineLoaderInterface`); its `alias` names the loader. */
    public const string LOADER_TAG = 'emagma_mcp_guidelines.loader';

    /** The guidelines this bundle ships, those of a generic online retailer. */
    public const array DEFAULTS = ['company', 'catalog_content', 'website_information', 'website_post', 'promotional_content', 'customer_message'];

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $defaults = array_map(
            static fn (string $name): array => ['resource' => '@EmagmaMcpGuidelinesBundle/resources/guidelines/' . $name . '.md', 'name' => null, 'loader' => null],
            self::DEFAULTS,
        );

        $definition->rootNode()
            ->children()
                ->arrayNode('resources')
                    ->info(
                        'One guideline per resource: a Markdown file (`@Bundle/...` or a path), named after the file;'
                        . ' or {resource, loader, name} for another loader. Not set: the six this bundle ships.',
                    )
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifString()
                            ->then(static fn (string $resource): array => ['resource' => $resource])
                        ->end()
                        ->children()
                            ->scalarNode('resource')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('loader')->defaultNull()->info('The alias of a loader; a .md file gets the Markdown loader.')->end()
                            ->scalarNode('name')->defaultNull()->info('The name of the guideline; the file name without .md by default.')->end()
                        ->end()
                    ->end()
                    ->defaultValue($defaults)
                ->end()
                ->arrayNode('intents')
                    ->info(
                        'What the model may be about to write. The description is what it matches its task against,'
                        . ' listed in the server\'s instructions; the guidelines are given in that order. Not set: none.',
                    )
                    ->useAttributeAsKey('intent')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('description')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('guidelines')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\McpGuidelinesBundle\\', 'core');
        McpCoreConfig::prependServerConfig($builder, ['registry' => ['tools' => ['Emagma\\McpGuidelinesBundle\\Tool\\']]]);
    }

    /**
     * @param array{resources: list<array{resource: string, loader: string|null, name: string|null}>, intents: array<string, array{description: string, guidelines: list<string>}>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        // The resources as configured: GuidelinesPass resolves and checks them once every loader is registered.
        $builder->getDefinition('emagma_mcp_guidelines.resolver')
            ->replaceArgument(1, $config['resources'])
            ->replaceArgument(2, $config['intents']);
        $builder->getDefinition('emagma_mcp_guidelines.instruction')->replaceArgument(0, $config['intents']);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new GuidelinesPass());
    }
}
