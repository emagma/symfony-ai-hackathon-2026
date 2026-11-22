<?php

declare(strict_types=1);

namespace Emagma\McpSkillsBundle;

use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Serves the skills of the business (Agent Skills folders) to the clients of the MCP server.
 */
final class EmagmaMcpSkillsBundle extends AbstractBundle
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
                    ->info('Where the business keeps its skills: one folder per skill, named like it, with its SKILL.md. `MCP_SKILLS_DIR` by default.')
                    ->cannotBeEmpty()
                    ->defaultValue('%env(resolve:MCP_SKILLS_DIR)%')
                ->end()
                ->scalarNode('pack')
                    ->info('The name of the set, in the URIs of the skills: `skill://<pack>/<name>/SKILL.md`.')
                    ->cannotBeEmpty()
                    ->defaultValue('merchant')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !\is_string($v) || 1 !== preg_match(SkillRepository::NAME, $v))
                        ->thenInvalid('The pack is lowercase words joined by hyphens, got %s.')
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\McpSkillsBundle\\', 'core');
        McpCoreConfig::prependServerConfig($builder, ['registry' => ['tools' => ['Emagma\\McpSkillsBundle\\Tool\\']]]);
    }

    /**
     * @param array{directory: string, pack: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('emagma_mcp_skills.directory', $config['directory'])
            ->set('emagma_mcp_skills.pack', $config['pack']);

        $container->import('../config/services.php');
    }
}
