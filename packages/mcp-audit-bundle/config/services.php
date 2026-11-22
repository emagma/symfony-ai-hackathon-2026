<?php

declare(strict_types=1);

use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\AuditWriter;
use Emagma\McpAuditBundle\Command\ForgetPersonalDataCommand;
use Emagma\McpAuditBundle\Command\PurgeAuditCommand;
use Emagma\McpAuditBundle\EventListener\AuditEntryLockListener;
use Emagma\McpAuditBundle\EventListener\AuditTableListener;
use Emagma\McpAuditBundle\Forget\PersonalDataEraser;
use Emagma\McpAuditBundle\Repository\AuditRepository;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Explicit definitions, ids prefixed by the alias. The class and interface aliases are what the
 * tools autowire and what a host injects. The interfaces of the core (RevertTargetInterface,
 * SubjectReferencesInterface) get their default in DefaultRevertTargetPass, after every bundle
 * had its say.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('emagma_mcp_audit.repository', AuditRepository::class)
        ->args([
            service('doctrine'),
        ]);
    $services->alias(AuditRepositoryInterface::class, 'emagma_mcp_audit.repository');

    $services->set('emagma_mcp_audit.writer', AuditWriter::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(SensitiveDataRedactor::class),
            service(ActorProviderInterface::class),
            service('logger')->nullOnInvalid(),
            service(ToolClassMap::class),
        ])
        ->tag('monolog.logger', ['channel' => 'emagma_mcp_audit']);
    $services->alias(WriteJournalInterface::class, 'emagma_mcp_audit.writer');
    $services->alias(AuditWriter::class, 'emagma_mcp_audit.writer');

    $services->set('emagma_mcp_audit.scope', AuditScope::class)
        ->args([
            service(GrantsProviderInterface::class),
            service(ActorProviderInterface::class),
            service(ToolPermissionResolver::class),
            service(ToolClassMap::class),
        ]);
    $services->alias(AuditScope::class, 'emagma_mcp_audit.scope');

    $services->set('emagma_mcp_audit.personal_data_eraser', PersonalDataEraser::class)
        ->args([
            service('emagma_mcp_audit.repository'),
            service('emagma_mcp_audit.writer'),
            service('doctrine.orm.entity_manager'),
            service(SensitiveDataRedactor::class),
            service(SubjectReferencesInterface::class),
        ]);
    $services->alias(PersonalDataEraser::class, 'emagma_mcp_audit.personal_data_eraser');

    $services->set('emagma_mcp_audit.listener.audit_table', AuditTableListener::class)
        ->args([
            param('emagma_mcp_audit.table'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);

    $services->set('emagma_mcp_audit.listener.audit_entry_lock', AuditEntryLockListener::class)
        ->tag('doctrine.event_listener', ['event' => 'preUpdate']);

    $services->set('emagma_mcp_audit.command.purge', PurgeAuditCommand::class)
        ->args([
            service('emagma_mcp_audit.repository'),
            param('emagma_mcp_audit.retention'),
        ])
        ->tag('console.command');

    $services->set('emagma_mcp_audit.command.forget_personal_data', ForgetPersonalDataCommand::class)
        ->args([
            service('emagma_mcp_audit.personal_data_eraser'),
        ])
        ->tag('console.command');

    // The unit symfony/mcp-bundle discovers: loaded by namespace, autowired (spec D3).
    $services->load('Emagma\\McpAuditBundle\\Tool\\', '../src/Tool/*')
        ->autowire()
        ->autoconfigure();
};
