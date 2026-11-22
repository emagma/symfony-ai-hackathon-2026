<?php

declare(strict_types=1);

use Emagma\McpCoreBundle\Call\LocalToolCaller;
use Emagma\McpTasksBundle\Command\FinishTaskCommand;
use Emagma\McpTasksBundle\Command\InterruptTaskCommand;
use Emagma\McpTasksBundle\Command\PlayStepCommand;
use Emagma\McpTasksBundle\Controller\AuthorizeController;
use Emagma\McpTasksBundle\Delegation\DelegationInterface;
use Emagma\McpTasksBundle\Delegation\OfflineTokenDelegation;
use Emagma\McpTasksBundle\Delegation\TokenVault;
use Emagma\McpTasksBundle\Delegation\TokenVaultInterface;
use Emagma\McpTasksBundle\Llm\AiLlmTransformer;
use Emagma\McpTasksBundle\Llm\LlmTransformerInterface;
use Emagma\McpTasksBundle\Notification\NotifierTaskNotifier;
use Emagma\McpTasksBundle\Orchestration\StepExecutor;
use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Orchestration\TaskOrchestratorInterface;
use Emagma\McpTasksBundle\Reading\AiScriptReader;
use Emagma\McpTasksBundle\Reading\ScriptReaderInterface;
use Emagma\McpTasksBundle\Runner\CoreToolInvoker;
use Emagma\McpTasksBundle\Runner\StepRunner;
use Emagma\McpTasksBundle\Runner\TaskLimits;
use Emagma\McpTasksBundle\Runner\ToolInvokerInterface;
use Emagma\McpTasksBundle\Sandbox\HardenedIni;
use Emagma\McpTasksBundle\Sandbox\HardenedIniWarmer;
use Emagma\McpTasksBundle\Sandbox\ProcessSandbox;
use Emagma\McpTasksBundle\Sandbox\SandboxInterface;
use Emagma\McpTasksBundle\Script\ScriptInspector;
use Emagma\McpTasksBundle\Script\TaskFiles;
use Emagma\McpTasksBundle\Store\DoctrineTaskStore;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\McpTasksBundle\TaskNotifierInterface;
use Emagma\McpTasksBundle\TaskView;
use Emagma\McpTasksBundle\Tool\LlmTransform;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Explicit definitions, ids prefixed by the alias. The class and interface aliases are what the tools
 * autowire and what an orchestrator or a host injects. emagma_mcp_tasks.orchestrator, .platform and
 * .token_handler are aliases of the services the host named; the script linter is registered only
 * when it is enabled. What the configuration says reaches the services as emagma_mcp_tasks.* parameters.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('emagma_mcp_tasks.store', DoctrineTaskStore::class)
        ->args([
            service('doctrine.orm.entity_manager'),
        ]);
    $services->alias(TaskStoreInterface::class, 'emagma_mcp_tasks.store');
    $services->alias(DoctrineTaskStore::class, 'emagma_mcp_tasks.store');

    $services->set('emagma_mcp_tasks.files', TaskFiles::class)
        ->args([
            param('emagma_mcp_tasks.directory'),
        ]);
    $services->alias(TaskFiles::class, 'emagma_mcp_tasks.files');

    $services->set('emagma_mcp_tasks.script_inspector', ScriptInspector::class);
    $services->alias(ScriptInspector::class, 'emagma_mcp_tasks.script_inspector');

    $services->set('emagma_mcp_tasks.hardened_ini', HardenedIni::class)
        ->args([
            param('emagma_mcp_tasks.limits.memory_limit'),
            '%kernel.cache_dir%/mcp_tasks',
        ]);
    $services->alias(HardenedIni::class, 'emagma_mcp_tasks.hardened_ini');

    $services->set('emagma_mcp_tasks.hardened_ini_warmer', HardenedIniWarmer::class)
        ->args([
            service('emagma_mcp_tasks.hardened_ini'),
        ])
        ->tag('kernel.cache_warmer');

    $services->set('emagma_mcp_tasks.sandbox', ProcessSandbox::class)
        ->args([
            service('emagma_mcp_tasks.hardened_ini'),
        ]);
    $services->alias(SandboxInterface::class, 'emagma_mcp_tasks.sandbox');
    $services->alias(ProcessSandbox::class, 'emagma_mcp_tasks.sandbox');

    $services->set('emagma_mcp_tasks.limits', TaskLimits::class)
        ->args([
            param('emagma_mcp_tasks.limits.step_timeout'),
            param('emagma_mcp_tasks.limits.max_calls'),
        ]);
    $services->alias(TaskLimits::class, 'emagma_mcp_tasks.limits');

    $services->set('emagma_mcp_tasks.tool_invoker', CoreToolInvoker::class)
        ->args([
            service(LocalToolCaller::class),
            service('emagma_mcp_tasks.delegation'),
        ]);
    $services->alias(ToolInvokerInterface::class, 'emagma_mcp_tasks.tool_invoker');
    $services->alias(CoreToolInvoker::class, 'emagma_mcp_tasks.tool_invoker');

    $services->set('emagma_mcp_tasks.step_runner', StepRunner::class)
        ->args([
            service('emagma_mcp_tasks.sandbox'),
            service('emagma_mcp_tasks.tool_invoker'),
            service('emagma_mcp_tasks.store'),
            service('emagma_mcp_tasks.files'),
            service('clock'),
            service('emagma_mcp_tasks.limits'),
        ]);
    $services->alias(StepRunner::class, 'emagma_mcp_tasks.step_runner');

    $services->set('emagma_mcp_tasks.script_reader', AiScriptReader::class)
        ->args([
            service('emagma_mcp_tasks.platform'),
            param('emagma_mcp_tasks.model'),
            param('emagma_mcp_tasks.max_output_tokens'),
        ]);
    $services->alias(ScriptReaderInterface::class, 'emagma_mcp_tasks.script_reader');
    $services->alias(AiScriptReader::class, 'emagma_mcp_tasks.script_reader');

    $services->set('emagma_mcp_tasks.llm_transformer', AiLlmTransformer::class)
        ->args([
            service('emagma_mcp_tasks.platform'),
            param('emagma_mcp_tasks.model'),
            param('emagma_mcp_tasks.max_output_tokens'),
        ]);
    $services->alias(LlmTransformerInterface::class, 'emagma_mcp_tasks.llm_transformer');
    $services->alias(AiLlmTransformer::class, 'emagma_mcp_tasks.llm_transformer');

    $services->set('emagma_mcp_tasks.token_vault', TokenVault::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
            param('emagma_mcp_tasks.delegation.secret'),
        ]);
    $services->alias(TokenVaultInterface::class, 'emagma_mcp_tasks.token_vault');
    $services->alias(TokenVault::class, 'emagma_mcp_tasks.token_vault');

    $services->set('emagma_mcp_tasks.delegation', OfflineTokenDelegation::class)
        ->args([
            service('emagma_mcp_tasks.token_vault'),
            service('http_client'),
            service('emagma_mcp_tasks.token_handler'),
            service('security.token_storage'),
            service('clock'),
            param('emagma_mcp_tasks.delegation.authorize_url'),
            param('emagma_mcp_tasks.delegation.token_url'),
            param('emagma_mcp_tasks.delegation.client_id'),
            param('emagma_mcp_tasks.delegation.client_secret'),
            param('emagma_mcp_tasks.delegation.redirect_uri'),
            param('emagma_mcp_tasks.delegation.secret'),
        ]);
    $services->alias(DelegationInterface::class, 'emagma_mcp_tasks.delegation');
    $services->alias(OfflineTokenDelegation::class, 'emagma_mcp_tasks.delegation');

    $services->set('emagma_mcp_tasks.controller.authorize', AuthorizeController::class)
        ->args([
            service('emagma_mcp_tasks.delegation'),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set('emagma_mcp_tasks.notifier', NotifierTaskNotifier::class)
        ->args([
            service('security.token_storage'),
            param('emagma_mcp_tasks.sender'),
            service('notifier')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
        ]);
    $services->alias(TaskNotifierInterface::class, 'emagma_mcp_tasks.notifier');
    $services->alias(NotifierTaskNotifier::class, 'emagma_mcp_tasks.notifier');

    // The orchestrator is the host's: emagma_mcp_tasks.orchestrator is an alias the bundle sets from its configuration.
    $services->alias(TaskOrchestratorInterface::class, 'emagma_mcp_tasks.orchestrator');

    $services->set('emagma_mcp_tasks.task_manager', TaskManager::class)
        ->args([
            service('emagma_mcp_tasks.store'),
            service('emagma_mcp_tasks.files'),
            service('emagma_mcp_tasks.script_inspector'),
            service('emagma_mcp_tasks.tool_invoker'),
            service('emagma_mcp_tasks.script_reader'),
            service('emagma_mcp_tasks.delegation'),
            service('emagma_mcp_tasks.orchestrator'),
            service('clock'),
            service('emagma_mcp_tasks.script_linter')->nullOnInvalid(),
        ]);
    $services->alias(TaskManager::class, 'emagma_mcp_tasks.task_manager');

    $services->set('emagma_mcp_tasks.task_view', TaskView::class)
        ->args([
            service('emagma_mcp_tasks.store'),
            service('emagma_mcp_tasks.files'),
        ]);
    $services->alias(TaskView::class, 'emagma_mcp_tasks.task_view');

    // What an orchestrator calls.
    $services->set('emagma_mcp_tasks.step_executor', StepExecutor::class)
        ->args([
            service('emagma_mcp_tasks.store'),
            service('emagma_mcp_tasks.step_runner'),
            service('emagma_mcp_tasks.delegation'),
            service('emagma_mcp_tasks.notifier'),
            service('clock'),
        ]);
    $services->alias(StepExecutor::class, 'emagma_mcp_tasks.step_executor');

    $services->set('emagma_mcp_tasks.lifecycle', TaskLifecycle::class)
        ->args([
            service('emagma_mcp_tasks.store'),
            service('emagma_mcp_tasks.delegation'),
            service('emagma_mcp_tasks.notifier'),
            service('clock'),
        ]);
    $services->alias(TaskLifecycle::class, 'emagma_mcp_tasks.lifecycle');

    $services->set('emagma_mcp_tasks.command.play_step', PlayStepCommand::class)
        ->args([
            service('emagma_mcp_tasks.step_executor'),
        ])
        ->tag('console.command');

    $services->set('emagma_mcp_tasks.command.finish_task', FinishTaskCommand::class)
        ->args([
            service('emagma_mcp_tasks.lifecycle'),
            service('emagma_mcp_tasks.store'),
        ])
        ->tag('console.command');

    $services->set('emagma_mcp_tasks.command.interrupt_task', InterruptTaskCommand::class)
        ->args([
            service('emagma_mcp_tasks.lifecycle'),
            service('emagma_mcp_tasks.store'),
        ])
        ->tag('console.command');

    // The unit symfony/mcp-bundle discovers: loaded by namespace, autowired (spec D3).
    $services->load('Emagma\\McpTasksBundle\\Tool\\', '../src/Tool/*')
        ->autowire()
        ->autoconfigure();
    $services->get(LlmTransform::class)
        ->arg('$maxInputLength', param('emagma_mcp_tasks.limits.max_input_length'));
};
