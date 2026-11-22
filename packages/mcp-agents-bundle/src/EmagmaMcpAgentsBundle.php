<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle;

use Emagma\McpAgentsBundle\DependencyInjection\Compiler\ScheduledAgentsPass;
use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Brings its own wiring as prepended defaults: the transports and the schedule's cache pool, the tool on the
 * core's server, its permission domain, the Doctrine mapping and the migrations.
 */
final class EmagmaMcpAgentsBundle extends AbstractBundle
{
    /** The transport of the passes: short, safe to replay (a report is recorded once per source). */
    public const string TRANSPORT = 'mcp_agents';

    public const string FAILURE_TRANSPORT = 'failed_mcp_agents';

    /** The parameter `MCP_AGENTS_TRANSPORT_DSN` falls back to when it is not set, or empty. */
    public const string DSN_PARAMETER = 'emagma_mcp_agents.transport_dsn';

    /** The queue of the host's database. */
    public const string DEFAULT_DSN = 'doctrine://default?queue_name=mcp_agents';

    public const string FAILURE_DSN = 'doctrine://default?queue_name=failed_mcp_agents';

    /** The cache pool of the schedule's state, on the host's database. */
    public const string SCHEDULE_POOL = 'emagma_mcp_agents.schedule';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('recipients')
                    ->info('Who receives the alerts: email addresses. `MCP_AGENT_ALERT_EMAIL` (comma-separated) by default; none, and the analyses only go to the journal.')
                    ->beforeNormalization()->ifString()->then(static fn (string $value): array => array_values(array_filter(array_map(trim(...), explode(',', $value)))))->end()
                    ->scalarPrototype()->end()
                    ->defaultValue(['%env(default::MCP_AGENT_ALERT_EMAIL)%'])
                ->end()
                ->scalarNode('sender')
                    ->info('The From of the alerts sent by email.')
                    ->cannotBeEmpty()
                    ->defaultValue('mcp-agents@localhost')
                ->end()
                ->enumNode('alert_from')
                    ->info('The lowest level of an analysis that raises an alert through Symfony Notifier; the others stay in the journal, read with list_agent_reports.')
                    ->values(array_map(static fn (Level $level): string => $level->value, Level::cases()))
                    ->defaultValue(Level::High->value)
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\McpAgentsBundle\\', 'core');
        McpCoreConfig::prependServerConfig($builder, ['registry' => ['tools' => ['Emagma\\McpAgentsBundle\\Tool\\']]]);

        if ($builder->hasExtension('framework')) {
            $builder->prependExtensionConfig('framework', [
                'messenger' => [
                    'transports' => [
                        // Retried: a pass replayed records nothing twice (uniq_mcp_agent_report_source).
                        self::TRANSPORT => [
                            'dsn' => '%env(default:' . self::DSN_PARAMETER . ':MCP_AGENTS_TRANSPORT_DSN)%',
                            'retry_strategy' => ['max_retries' => 3, 'delay' => 30000, 'multiplier' => 2],
                            'failure_transport' => self::FAILURE_TRANSPORT,
                        ],
                        self::FAILURE_TRANSPORT => ['dsn' => self::FAILURE_DSN],
                    ],
                    // The schedule's messages come through RedispatchMessage: the same route.
                    'routing' => [RunAgent::class => self::TRANSPORT],
                ],
                'cache' => [
                    'pools' => [self::SCHEDULE_POOL => ['adapter' => 'cache.adapter.doctrine_dbal']],
                ],
            ]);
        }

        if (!$builder->hasParameter(self::DSN_PARAMETER)) {
            $builder->setParameter(self::DSN_PARAMETER, self::DEFAULT_DSN);
        }

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'EmagmaMcpAgentsBundle' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => $this->getPath() . '/src/Entity',
                            'prefix' => 'Emagma\\McpAgentsBundle\\Entity',
                            'alias' => 'EmagmaMcpAgents',
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => ['Emagma\\McpAgentsBundle\\Migrations' => $this->getPath() . '/migrations'],
            ]);
        }
    }

    /**
     * @param array{alert_from: string, sender: string, recipients: list<string|null>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('emagma_mcp_agents.recipients', $config['recipients'])
            ->set('emagma_mcp_agents.sender', $config['sender'])
            ->set('emagma_mcp_agents.alert_from', Level::from($config['alert_from']))
        ;

        $container->import('../config/services.php');

        $builder->registerForAutoconfiguration(ScheduledAgentInterface::class)->addTag(ScheduledAgentsPass::TAG);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ScheduledAgentsPass());
    }
}
