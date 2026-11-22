<?php

declare(strict_types=1);

use Emagma\McpTasksMessengerBundle\EmagmaMcpTasksMessengerBundle;
use Emagma\McpTasksMessengerBundle\EventListener\InterruptedTasksListener;
use Emagma\McpTasksMessengerBundle\Handler\RunStepHandler;
use Emagma\McpTasksMessengerBundle\MessengerOrchestrator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/*
 * Explicit definitions, ids prefixed by the alias. The orchestrator is the id that
 * emagma_mcp_tasks.orchestrator names by default (see EmagmaMcpTasksMessengerBundle::prependExtension()).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()->private();

    $services->set(EmagmaMcpTasksMessengerBundle::ORCHESTRATOR, MessengerOrchestrator::class)
        ->args([service(MessageBusInterface::class)]);
    $services->alias(MessengerOrchestrator::class, EmagmaMcpTasksMessengerBundle::ORCHESTRATOR);

    $services->set('emagma_mcp_tasks_messenger.run_step_handler', RunStepHandler::class)
        ->args([
            service('emagma_mcp_tasks.step_executor'),
            service('emagma_mcp_tasks.lifecycle'),
            service('emagma_mcp_tasks.store'),
            service(MessageBusInterface::class),
        ])
        ->tag('messenger.message_handler');

    $services->set('emagma_mcp_tasks_messenger.interrupted_tasks_listener', InterruptedTasksListener::class)
        ->args([
            service('emagma_mcp_tasks.store'),
            service('emagma_mcp_tasks.lifecycle'),
        ])
        ->tag('kernel.event_listener', ['event' => WorkerStartedEvent::class]);
};
