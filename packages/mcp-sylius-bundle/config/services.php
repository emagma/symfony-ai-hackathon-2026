<?php

declare(strict_types=1);

use Emagma\McpCoreBundle\Journal\RevertTargetInterface;
use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClient;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiRevertTarget;
use Emagma\McpSyliusBundle\AdminApi\CapabilitiesReader;
use Emagma\McpSyliusBundle\AdminApi\Command\CheckShopExtensionsCommand;
use Emagma\McpSyliusBundle\AdminApi\Command\CheckSyliusVersionCommand;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensionGate;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensions;
use Emagma\McpSyliusBundle\AdminApi\Gate\SyliusVersionGate;
use Emagma\McpSyliusBundle\AdminApi\JwtTokenProvider;
use Emagma\McpSyliusBundle\AdminApi\JwtTokenProviderInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLines;
use Emagma\McpSyliusBundle\AdminApi\SyliusSensitiveKeys;
use Emagma\McpSyliusBundle\AdminApi\SyliusSubjectReferences;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Emagma\McpSyliusBundle\File\FileInput;
use Emagma\McpSyliusBundle\File\RemoteFileDownloader;
use Emagma\McpSyliusBundle\SyliusInstruction;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

/*
 * Explicit definitions, ids prefixed by the alias; the class and interface aliases are what the tools
 * autowire and what the line bundles and a host inject. The tools are the exception: the unit the MCP
 * bundle discovers, loaded by namespace at the end. The options are parameters the bundle class sets.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // First in the server's instructions (the core assembles them): the conventions of every tool.
    $services->set('emagma_mcp_sylius.instruction', SyliusInstruction::class)
        ->args([
            param('emagma_mcp_sylius.default_locale'),
            param('emagma_mcp_sylius.audit'),
        ])
        ->tag('emagma_mcp_core.instruction', ['priority' => 100]);

    $services->set('emagma_mcp_sylius.sensitive_keys', SyliusSensitiveKeys::class)
        ->args([
            [], // the personal keys of the outputs, set by the bundle class
        ])
        ->tag('emagma_mcp_core.sensitive_keys');
    $services->alias(SyliusSensitiveKeys::class, 'emagma_mcp_sylius.sensitive_keys');

    $services->set('emagma_mcp_sylius.revert_target', AdminApiRevertTarget::class)
        ->args([
            service('emagma_mcp_sylius.admin_api.client'),
            service('emagma_mcp_sylius.admin_api.adapter'),
        ]);
    $services->alias(AdminApiRevertTarget::class, 'emagma_mcp_sylius.revert_target');
    $services->alias(RevertTargetInterface::class, 'emagma_mcp_sylius.revert_target');

    $services->set('emagma_mcp_sylius.subject_references', SyliusSubjectReferences::class);
    $services->alias(SyliusSubjectReferences::class, 'emagma_mcp_sylius.subject_references');
    $services->alias(SubjectReferencesInterface::class, 'emagma_mcp_sylius.subject_references');

    // The token is cached in cache.app, so that the PHP processes share one authentication.
    $services->set('emagma_mcp_sylius.admin_api.jwt_token_provider', JwtTokenProvider::class)
        ->args([
            service('emagma_mcp_sylius.admin_api.http_client'),
            param('emagma_mcp_sylius.service_account.email'),
            param('emagma_mcp_sylius.service_account.password'),
            service('cache.app'),
        ]);
    $services->alias(JwtTokenProvider::class, 'emagma_mcp_sylius.admin_api.jwt_token_provider');
    // A host may decorate it to carry a per-user identity instead of the service account.
    $services->alias(JwtTokenProviderInterface::class, 'emagma_mcp_sylius.admin_api.jwt_token_provider');

    $services->set('emagma_mcp_sylius.admin_api.adapter', SyliusApiAdapterInterface::class)
        ->factory([service('emagma_mcp_sylius.lines'), 'adapter'])
        ->args([
            service('emagma_mcp_sylius.version'),
            param('emagma_mcp_sylius.api_url'),
        ]);
    $services->alias(SyliusApiAdapterInterface::class, 'emagma_mcp_sylius.admin_api.adapter');

    $services->set('emagma_mcp_sylius.admin_api.client', AdminApiClient::class)
        ->args([
            service('emagma_mcp_sylius.admin_api.http_client'),
            service('emagma_mcp_sylius.admin_api.jwt_token_provider'),
            service('logger'),
            service('emagma_mcp_sylius.admin_api.adapter'),
        ])
        ->tag('monolog.logger', ['channel' => 'emagma_mcp_sylius']);
    $services->alias(AdminApiClient::class, 'emagma_mcp_sylius.admin_api.client');
    $services->alias(AdminApiClientInterface::class, 'emagma_mcp_sylius.admin_api.client');

    // The download URL is a tool argument: never a private address, whatever an allowed host resolves to.
    $services->set('emagma_mcp_sylius.file_download.http_client.public_only', NoPrivateNetworkHttpClient::class)
        ->args([
            service('emagma_mcp_sylius.file_download.http_client'),
        ]);

    $services->set('emagma_mcp_sylius.file.downloader', RemoteFileDownloader::class)
        ->args([
            service('emagma_mcp_sylius.file_download.http_client.public_only'),
            param('emagma_mcp_sylius.file_params.allowed_hosts'),
        ]);
    $services->alias(RemoteFileDownloader::class, 'emagma_mcp_sylius.file.downloader');

    $services->set('emagma_mcp_sylius.file.input', FileInput::class)
        ->args([
            service('emagma_mcp_sylius.file.downloader'),
        ]);
    $services->alias(FileInput::class, 'emagma_mcp_sylius.file.input');

    // Read at runtime: one compiled container serves every MCP_SHOP_EXTENSIONS value.
    $services->set('emagma_mcp_sylius.shop_extensions', ShopExtensions::class)
        ->args([
            param('emagma_mcp_sylius.shop_extensions'),
            [], // the names the installed bundles declare, set by ShopExtensionsPass
        ]);
    $services->alias(ShopExtensions::class, 'emagma_mcp_sylius.shop_extensions');

    $services->set('emagma_mcp_sylius.lines', SyliusLines::class)
        ->args([
            tagged_iterator('emagma_mcp_sylius.line'),
        ]);
    $services->alias(SyliusLines::class, 'emagma_mcp_sylius.lines');

    $services->set('emagma_mcp_sylius.version', SyliusVersion::class)
        ->factory([service('emagma_mcp_sylius.lines'), 'configured'])
        ->args([
            param('emagma_mcp_sylius.sylius_version'),
        ]);
    $services->alias(SyliusVersion::class, 'emagma_mcp_sylius.version');

    // Asked before the version gate: its reason is the one a call gets when both apply.
    $services->set('emagma_mcp_sylius.gate.shop_extension', ShopExtensionGate::class)
        ->args([
            service('emagma_mcp_sylius.shop_extensions'),
        ])
        ->tag('emagma_mcp_core.tool_gate', ['priority' => 10]);
    $services->alias(ShopExtensionGate::class, 'emagma_mcp_sylius.gate.shop_extension');

    $services->set('emagma_mcp_sylius.gate.sylius_version', SyliusVersionGate::class)
        ->args([
            service('emagma_mcp_sylius.version'),
        ])
        ->tag('emagma_mcp_core.tool_gate');
    $services->alias(SyliusVersionGate::class, 'emagma_mcp_sylius.gate.sylius_version');

    $services->set('emagma_mcp_sylius.capabilities_reader', CapabilitiesReader::class)
        ->args([
            service('emagma_mcp_sylius.admin_api.client'),
        ]);
    $services->alias(CapabilitiesReader::class, 'emagma_mcp_sylius.capabilities_reader');

    $services->set('emagma_mcp_sylius.command.check_shop_extensions', CheckShopExtensionsCommand::class)
        ->args([
            service('emagma_mcp_sylius.capabilities_reader'),
            service('emagma_mcp_sylius.shop_extensions'),
        ])
        ->tag('console.command');

    $services->set('emagma_mcp_sylius.command.check_sylius_version', CheckSyliusVersionCommand::class)
        ->args([
            service('emagma_mcp_sylius.capabilities_reader'),
            service('emagma_mcp_sylius.version'),
            service('emagma_mcp_sylius.lines'),
        ])
        ->tag('console.command');

    // The tools: no exclusion list, everything under Tool/ is a service, a DTO there would fail the container build.
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private()
        ->bind('string $defaultLocale', '%emagma_mcp_sylius.default_locale%')
        // The administrator tools refuse to disable or delete it: that would end the server's own access.
        ->bind('string $serviceAccountEmail', '%emagma_mcp_sylius.service_account.email%');

    $services->load('Emagma\\McpSyliusBundle\\Tool\\', '../src/Tool/*');
};
