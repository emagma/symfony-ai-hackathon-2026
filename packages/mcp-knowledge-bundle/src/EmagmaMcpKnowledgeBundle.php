<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle;

use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Indexes the procedures of a business, Markdown files, in a Symfony AI store and serves the search tool.
 */
final class EmagmaMcpKnowledgeBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('directory')
                    ->info('Where the business keeps its procedures, as Markdown files (sub-directories included). `MCP_KNOWLEDGE_DIR` by default.')
                    ->cannotBeEmpty()
                    ->defaultValue('%env(resolve:MCP_KNOWLEDGE_DIR)%')
                ->end()
                ->scalarNode('index_file')
                    ->info('The SQLite file of the index, written by the server and its worker. Rebuilt from the directory at any time: `mcp:knowledge:index --rebuild`.')
                    ->cannotBeEmpty()
                    ->defaultValue('%kernel.project_dir%/var/knowledge/index.sqlite')
                ->end()
                ->arrayNode('embeddings')
                    ->info('With a platform and a model the search is hybrid, by words and by meaning; without, by words only. After a change: `mcp:knowledge:index --rebuild`.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('platform')->info('A Symfony AI platform service that serves embeddings (`ai.platform.mistral`, `ai.platform.voyage`, ...).')->defaultNull()->end()
                        ->scalarNode('model')->info('Its embeddings model (`mistral-embed`, `voyage-3`, ...).')->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\McpKnowledgeBundle\\', 'knowledge');
        McpCoreConfig::prependServerConfig($builder, ['registry' => ['tools' => ['Emagma\\McpKnowledgeBundle\\Tool\\']]]);
    }

    /**
     * @param array{directory: string, index_file: string, embeddings: array{platform: string|null, model: string|null}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->getDefinition('emagma_mcp_knowledge.pdo')->replaceArgument(0, $config['index_file']);
        $index = $builder->getDefinition('emagma_mcp_knowledge.index')
            ->replaceArgument(2, $config['directory'])
            ->replaceArgument(3, $config['index_file'] . '.manifest.json');

        if (null !== $config['embeddings']['platform'] && null !== $config['embeddings']['model']) {
            $index->replaceArgument(4, new Definition(Vectorizer::class, [new Reference($config['embeddings']['platform']), $config['embeddings']['model']]));
        }

        // The procedures of the application unless the host points elsewhere.
        if (!$builder->hasParameter('env(MCP_KNOWLEDGE_DIR)')) {
            $builder->setParameter('env(MCP_KNOWLEDGE_DIR)', '%kernel.project_dir%/knowledge');
        }
    }
}
