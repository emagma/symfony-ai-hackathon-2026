<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle;

use Emagma\McpAuditBundle\DependencyInjection\Compiler\DefaultRevertTargetPass;
use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Brings its own wiring as prepended defaults: the tools on the core's server, their permission domain,
 * the Doctrine mapping, the migrations and the log channel.
 */
final class EmagmaMcpAuditBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('table')
                    ->info('The table of the audit. The bundle\'s migration creates the default one; another name needs a migration of your own.')
                    ->cannotBeEmpty()
                    ->defaultValue('mcp_audit')
                ->end()
                ->scalarNode('retention')
                    ->info('How long an audit entry is kept, as an ISO 8601 duration (`P1Y`, `P90D`). Nothing is deleted by itself: `mcp:audit:purge` applies it when run.')
                    ->defaultValue('P1Y')
                    ->validate()
                        ->always(static function (mixed $value): string {
                            if (!\is_string($value) || '' === $value) {
                                throw new \InvalidArgumentException('emagma_mcp_audit.retention must be an ISO 8601 duration such as "P1Y".');
                            }

                            try {
                                new \DateInterval($value);
                            } catch (\Exception) {
                                throw new \InvalidArgumentException(\sprintf('emagma_mcp_audit.retention "%s" is not an ISO 8601 duration such as "P1Y".', $value));
                            }

                            return $value;
                        })
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\McpAuditBundle\\', 'core');
        McpCoreConfig::prependServerConfig($builder, ['registry' => ['tools' => ['Emagma\\McpAuditBundle\\Tool\\']]]);

        if ($builder->hasExtension('monolog')) {
            $builder->prependExtensionConfig('monolog', ['channels' => ['emagma_mcp_audit']]);
        }

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'EmagmaMcpAuditBundle' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => $this->getPath() . '/src/Entity',
                            'prefix' => 'Emagma\\McpAuditBundle\\Entity',
                            'alias' => 'EmagmaMcpAudit',
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => ['Emagma\\McpAuditBundle\\Migrations' => $this->getPath() . '/migrations'],
            ]);
        }
    }

    /**
     * @param array{table: string, retention: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('emagma_mcp_audit.table', $config['table'])
            ->set('emagma_mcp_audit.retention', $config['retention'])
        ;

        $container->import('../config/services.php');
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Before the autowiring pass: the tools autowire the two interfaces it may alias.
        $container->addCompilerPass(new DefaultRevertTargetPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, priority: 100);
    }
}
