<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle;

use Emagma\McpTasksMessengerBundle\Message\RunStep;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The orchestrator of the tasks on Symfony Messenger, with nothing to configure: registered beside
 * EmagmaMcpTasksBundle it is `emagma_mcp_tasks.orchestrator`, unless the host names another one.
 */
final class EmagmaMcpTasksMessengerBundle extends AbstractBundle
{
    public const string ORCHESTRATOR = 'emagma_mcp_tasks_messenger.orchestrator';

    public const string TRANSPORT = 'mcp_tasks';

    /** What broke the worker itself, kept to be read: replaying it plays nothing (the step is `Skipped`). */
    public const string FAILURE_TRANSPORT = 'failed_mcp_tasks';

    public const string FAILURE_DSN = 'doctrine://default?queue_name=failed_mcp_tasks';

    /** The queue of the server's database. A step may last hours: the message must not go to another consumer meanwhile. */
    public const string DEFAULT_DSN = 'doctrine://default?queue_name=mcp_tasks&redeliver_timeout=172800';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // A default: the host may still name another orchestrator in its own configuration.
        if ($builder->hasExtension('emagma_mcp_tasks')) {
            $builder->prependExtensionConfig('emagma_mcp_tasks', ['orchestrator' => self::ORCHESTRATOR]);
        }

        if ($builder->hasExtension('framework')) {
            // A queue of its own, and no retry: a step that wrote must never be played twice. The handler suspends the
            // task on any error: the failure transport only receives what broke the worker itself.
            $builder->prependExtensionConfig('framework', [
                'messenger' => [
                    'transports' => [
                        self::TRANSPORT => [
                            'dsn' => '%env(MCP_TASKS_TRANSPORT_DSN)%',
                            'retry_strategy' => ['max_retries' => 0],
                            'failure_transport' => self::FAILURE_TRANSPORT,
                        ],
                        self::FAILURE_TRANSPORT => ['dsn' => self::FAILURE_DSN],
                    ],
                    'routing' => [RunStep::class => self::TRANSPORT],
                ],
            ]);
        }

        if (!$builder->hasParameter('env(MCP_TASKS_TRANSPORT_DSN)')) {
            $builder->setParameter('env(MCP_TASKS_TRANSPORT_DSN)', self::DEFAULT_DSN);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
