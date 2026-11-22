<?php

declare(strict_types=1);

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\Alert\AgentAlerter;
use Emagma\McpAgentsBundle\Store\AgentReportStoreInterface;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusEventsBundle\Event\ShopEventRecorded;
use Emagma\McpSyliusEventsBundle\Store\ShopEventStoreInterface;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\SymfonyAiHackathon2026Bundle\Command\DemosCommand;
use Emagma\SymfonyAiHackathon2026Bundle\Command\RunFraudAgentCommand;
use Emagma\SymfonyAiHackathon2026Bundle\DemoRepository;
use Emagma\SymfonyAiHackathon2026Bundle\EventListener\NewAccountListener;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\AgentRiskJudge;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\DnsDomainChecker;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\DomainCheckerInterface;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\EmailRiskRules;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\FraudAgent;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\RiskJudgeInterface;
use Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation\Tool\ProductTranslate;
use Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation\TranslationTask;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Explicit definitions, ids prefixed by the alias. The platform of the fraud judge is the host's service: the
 * bundle class sets it. The tools are the units symfony/mcp-bundle discovers: autoconfigured (spec D3).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $alias = 'emagma_symfony_ai_hackathon_2026';

    $services->set($alias . '.demos', DemoRepository::class)
        ->args([param($alias . '.demos_directory')]);
    $services->alias(DemoRepository::class, $alias . '.demos');

    $services->set($alias . '.command.demos', DemosCommand::class)
        ->args([service($alias . '.demos')])
        ->tag('console.command');

    $services->set($alias . '.fraud.domain_checker', DnsDomainChecker::class);
    $services->alias(DomainCheckerInterface::class, $alias . '.fraud.domain_checker');

    $services->set($alias . '.fraud.rules', EmailRiskRules::class)
        ->args([
            service($alias . '.fraud.domain_checker'),
            param($alias . '.fraud.disposable_domains'),
        ]);
    $services->alias(EmailRiskRules::class, $alias . '.fraud.rules');

    $services->set($alias . '.fraud.judge', AgentRiskJudge::class)
        ->args([
            '$platform' => null, // the host's platform, set by the bundle class
            '$model' => param($alias . '.fraud.model'),
            '$maxOutputTokens' => param($alias . '.fraud.max_output_tokens'),
        ]);
    $services->alias(RiskJudgeInterface::class, $alias . '.fraud.judge');

    // A ScheduledAgentInterface: the agents bundle runs and schedules it.
    $services->set($alias . '.fraud.agent', FraudAgent::class)
        ->args([
            service(ShopEventStoreInterface::class),
            service(AgentReportStoreInterface::class),
            service(AdminApiClientInterface::class),
            service($alias . '.fraud.rules'),
            service($alias . '.fraud.judge'),
            service(AgentAlerter::class),
            service(ClockInterface::class),
            param($alias . '.fraud.every'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('emagma_mcp_agents.agent');
    $services->alias(FraudAgent::class, $alias . '.fraud.agent');

    // customer.created wakes the agent.
    $services->set($alias . '.fraud.new_account_listener', NewAccountListener::class)
        ->args([
            service(AgentRunner::class),
            param($alias . '.fraud.enabled'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_listener', ['event' => ShopEventRecorded::class]);

    $services->set($alias . '.command.fraud', RunFraudAgentCommand::class)
        ->args([service(AgentRunner::class)])
        ->tag('console.command');

    // The product translation: product_translate, a ready-made background task.
    $services->set($alias . '.translation_task', TranslationTask::class);
    $services->alias(TranslationTask::class, $alias . '.translation_task');

    $services->set($alias . '.tool.product_translate', ProductTranslate::class)
        ->args([
            service(AdminApiClientInterface::class),
            service($alias . '.translation_task'),
            service(TaskManager::class),
            service(ActorProviderInterface::class),
            param('emagma_mcp_sylius.default_locale'),
        ])
        ->autoconfigure();

    $services->load('Emagma\\SymfonyAiHackathon2026Bundle\\Tool\\', '../src/Tool/*')
        ->autowire()
        ->autoconfigure();
};
