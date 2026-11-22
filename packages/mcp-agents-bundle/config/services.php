<?php

declare(strict_types=1);

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\Alert\AgentAlerter;
use Emagma\McpAgentsBundle\Schedule\AgentsSchedule;
use Emagma\McpAgentsBundle\Schedule\RunAgentHandler;
use Emagma\McpAgentsBundle\Store\AgentReportStoreInterface;
use Emagma\McpAgentsBundle\Store\DoctrineAgentReportStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\NotifierInterface;

/*
 * Explicit definitions, ids prefixed by the alias. The class and interface aliases are what the agents of other
 * bundles inject. The agents are given to the runner and to the schedule by ScheduledAgentsPass. The lock factory
 * is the host's (framework.lock): the deduplication of RunAgent and the lock of a pass need a store every worker
 * shares.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('emagma_mcp_agents.report_store', DoctrineAgentReportStore::class)
        ->args([
            service('doctrine.orm.entity_manager'),
        ]);
    $services->alias(AgentReportStoreInterface::class, 'emagma_mcp_agents.report_store');

    $services->set('emagma_mcp_agents.alerter', AgentAlerter::class)
        ->args([
            service(NotifierInterface::class),
            service(ClockInterface::class),
            param('emagma_mcp_agents.recipients'),
            param('emagma_mcp_agents.sender'),
            param('emagma_mcp_agents.alert_from'),
        ]);
    $services->alias(AgentAlerter::class, 'emagma_mcp_agents.alerter');

    $services->set('emagma_mcp_agents.runner', AgentRunner::class)
        ->args([
            '$bus' => service(MessageBusInterface::class),
            '$locks' => service(LockFactory::class),
            '$agents' => null, // the locator ScheduledAgentsPass builds
            '$logger' => service('logger')->nullOnInvalid(),
        ]);
    $services->alias(AgentRunner::class, 'emagma_mcp_agents.runner');

    // #[AsSchedule] names the schedule: a process consumes scheduler_mcp_agents. Its state is the cache pool
    // the bundle prepends.
    $services->set('emagma_mcp_agents.schedule_provider', AgentsSchedule::class)
        ->args([
            '$agents' => null, // the locator ScheduledAgentsPass builds
            '$state' => service('emagma_mcp_agents.schedule'),
            '$locks' => service(LockFactory::class),
        ])
        ->tag('scheduler.schedule_provider', ['name' => AgentsSchedule::NAME]);

    $services->set('emagma_mcp_agents.run_agent_handler', RunAgentHandler::class)
        ->args([
            service('emagma_mcp_agents.runner'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('messenger.message_handler');

    // The unit symfony/mcp-bundle discovers: loaded by namespace, autowired (spec D3).
    $services->load('Emagma\\McpAgentsBundle\\Tool\\', '../src/Tool/*')
        ->autowire()
        ->autoconfigure();
};
