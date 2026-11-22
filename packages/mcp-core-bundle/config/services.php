<?php

declare(strict_types=1);

use Emagma\McpCoreBundle\Authorization\AllowAllGrantsProvider;
use Emagma\McpCoreBundle\Authorization\DenyAllGrantsProvider;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\ToolAuthorizationRegistry;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Call\LocalToolCaller;
use Emagma\McpCoreBundle\Call\ToolArgumentErrorMiddleware;
use Emagma\McpCoreBundle\Call\ToolCallHandler;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\Caller\McpCallerStore;
use Emagma\McpCoreBundle\Caller\NullActorProvider;
use Emagma\McpCoreBundle\Caller\SecurityActorProvider;
use Emagma\McpCoreBundle\Command\ListPermissionsCommand;
use Emagma\McpCoreBundle\EventListener\AllowAllGrantsWarningListener;
use Emagma\McpCoreBundle\EventListener\EmptyObjectArgumentListener;
use Emagma\McpCoreBundle\EventListener\UntrustedContentNoticeListener;
use Emagma\McpCoreBundle\Instruction\McpInstructions;
use Emagma\McpCoreBundle\Journal\NullWriteJournal;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\Privacy\PrivacyContextConfigurator;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationPolicyConfigurator;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Explicit definitions, ids prefixed by the alias. The interface and class aliases are what the other
 * bundles inject and what a host re-aliases (GrantsProviderInterface, ActorProviderInterface,
 * WriteJournalInterface). The arguments read from the configuration are set by the bundle class.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Authorization: deny-all until the host aliases GrantsProviderInterface to a real provider.
    $services->set('emagma_mcp_core.permission_resolver', ToolPermissionResolver::class)
        ->args([
            [],
        ]);
    $services->alias(ToolPermissionResolver::class, 'emagma_mcp_core.permission_resolver');

    $services->set('emagma_mcp_core.grants_provider.deny_all', DenyAllGrantsProvider::class);
    $services->alias(DenyAllGrantsProvider::class, 'emagma_mcp_core.grants_provider.deny_all');

    $services->set('emagma_mcp_core.grants_provider.allow_all', AllowAllGrantsProvider::class);
    $services->alias(AllowAllGrantsProvider::class, 'emagma_mcp_core.grants_provider.allow_all');

    $services->alias(GrantsProviderInterface::class, 'emagma_mcp_core.grants_provider.deny_all');

    $services->set('emagma_mcp_core.listener.allow_all_grants_warning', AllowAllGrantsWarningListener::class)
        ->args([
            service(GrantsProviderInterface::class),
            service('logger'),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onRequest', 'priority' => 1000])
        ->tag('monolog.logger', ['channel' => 'emagma_mcp_core_authorization']);

    // Decorates the registry of the server the core attaches to (set by the bundle class).
    $services->set('emagma_mcp_core.authorization_registry', ToolAuthorizationRegistry::class)
        ->args([
            service('.inner'),
            service('emagma_mcp_core.permission_resolver'),
            service(GrantsProviderInterface::class),
            service('logger'),
            tagged_iterator('emagma_mcp_core.tool_gate'),
        ])
        ->tag('monolog.logger', ['channel' => 'emagma_mcp_core_authorization']);

    // The undecorated registry: deny-all would hide every tool. The server is asked for so that the builder fills it.
    $services->set('emagma_mcp_core.command.list_permissions', ListPermissionsCommand::class)
        ->args([
            service('emagma_mcp_core.authorization_registry.inner'),
            service('emagma_mcp_core.permission_resolver'),
            service('emagma_mcp_core.server'),
        ])
        ->tag('console.command');

    // The tools/call chain. The tool locator (third argument) is set by ToolCallLocatorPass.
    $services->set('emagma_mcp_core.tool_call_handler', ToolCallHandler::class)
        ->args([
            service('emagma_mcp_core.registry'),
            tagged_iterator('emagma_mcp_core.tool_call_middleware'),
            null,
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'emagma_mcp_core'])
        ->tag('mcp.request_handler');

    // Innermost: the other middlewares see what the model receives.
    $services->set('emagma_mcp_core.tool_call_middleware.argument_error', ToolArgumentErrorMiddleware::class)
        ->tag('emagma_mcp_core.tool_call_middleware', ['priority' => -1000]);

    // The tool locator (second argument) is set by ToolCallLocatorPass; the server is asked for so that the registry is filled outside a request.
    $services->set('emagma_mcp_core.local_tool_caller', LocalToolCaller::class)
        ->args([
            service('emagma_mcp_core.registry'),
            null,
            service('emagma_mcp_core.server'),
        ]);
    $services->alias(LocalToolCaller::class, 'emagma_mcp_core.local_tool_caller');

    // The tools of the server by name, filled by ToolClassMapPass.
    $services->set('emagma_mcp_core.tool_class_map', ToolClassMap::class)
        ->args([
            [],
        ]);
    $services->alias(ToolClassMap::class, 'emagma_mcp_core.tool_class_map');

    $services->set('emagma_mcp_core.listener.empty_object_argument', EmptyObjectArgumentListener::class)
        ->args([
            service('emagma_mcp_core.registry'),
        ])
        ->tag('kernel.event_listener', ['event' => RequestEvent::class]);

    // Above a tracing listener, so that a trace records what the model receives. Its notice is set from the configuration.
    $services->set('emagma_mcp_core.listener.untrusted_content_notice', UntrustedContentNoticeListener::class)
        ->tag('kernel.event_listener', ['event' => ResponseEvent::class, 'priority' => 10]);

    // Re-applied on every request and command; its arguments come from emagma_mcp_core.write_confirmation.
    $services->set('emagma_mcp_core.listener.write_confirmation_policy', WriteConfirmationPolicyConfigurator::class)
        ->args([
            [],
            [],
            0.0,
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onRequest', 'priority' => 1024])
        ->tag('kernel.event_listener', ['event' => 'console.command', 'method' => 'onCommand', 'priority' => 1024]);

    // The caller: its OAuth client, filled by the host's access-token handler, and who it acts as.
    $services->set('emagma_mcp_core.caller_store', McpCallerStore::class)
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(McpCallerStore::class, 'emagma_mcp_core.caller_store');

    // Its mode, clients and secret come from emagma_mcp_core.privacy.
    $services->set('emagma_mcp_core.listener.privacy_context', PrivacyContextConfigurator::class)
        ->args([
            service('emagma_mcp_core.caller_store'),
            'pseudonymise',
            [],
            '%kernel.secret%',
        ])
        ->tag('kernel.event_listener', ['event' => RequestEvent::class, 'method' => 'onRequest', 'priority' => 1024])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set('emagma_mcp_core.actor_provider.null', NullActorProvider::class);
    $services->alias(NullActorProvider::class, 'emagma_mcp_core.actor_provider.null');

    $services->set('emagma_mcp_core.actor_provider.security', SecurityActorProvider::class)
        ->args([
            service('security.helper'),
        ]);
    $services->alias(SecurityActorProvider::class, 'emagma_mcp_core.actor_provider.security');

    $services->alias(ActorProviderInterface::class, 'emagma_mcp_core.actor_provider.security');

    // Nothing is recorded until a journal (the audit bundle) aliases WriteJournalInterface.
    $services->set('emagma_mcp_core.write_journal.null', NullWriteJournal::class);
    $services->alias(NullWriteJournal::class, 'emagma_mcp_core.write_journal.null');

    $services->alias(WriteJournalInterface::class, 'emagma_mcp_core.write_journal.null');

    // Its extra keys and marker come from emagma_mcp_core.redaction.
    $services->set('emagma_mcp_core.redactor', SensitiveDataRedactor::class)
        ->args([
            [],
            [],
            tagged_iterator('emagma_mcp_core.sensitive_keys'),
            SensitiveDataRedactor::MARKER,
        ]);
    $services->alias(SensitiveDataRedactor::class, 'emagma_mcp_core.redactor');

    // The server's instructions: every service tagged emagma_mcp_core.instruction, highest priority first.
    $services->set('emagma_mcp_core.instructions', McpInstructions::class)
        ->args([
            tagged_iterator('emagma_mcp_core.instruction'),
        ]);
};
