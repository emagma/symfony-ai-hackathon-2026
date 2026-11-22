<?php

declare(strict_types=1);

use Emagma\McpAuthBundle\Controller\OAuthMetadataController;
use Emagma\McpAuthBundle\EventListener\McpChallengeResponseListener;
use Emagma\McpAuthBundle\McpAuthenticationEntryPoint;
use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpAuthBundle\TokenGrantsProvider;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Explicit definitions, ids prefixed by the alias. The class aliases are what the host names
 * (the firewall's entry point, the routes' controller) and what a token handler injects.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // kernel.reset: grants never survive the request that minted them.
    $services->set('emagma_mcp_auth.grants_token_store', McpGrantsTokenStore::class)
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(McpGrantsTokenStore::class, 'emagma_mcp_auth.grants_token_store');

    // Replaces the core's default deny-all provider.
    $services->set('emagma_mcp_auth.token_grants_provider', TokenGrantsProvider::class)
        ->args([
            service('emagma_mcp_auth.grants_token_store'),
        ]);
    $services->alias(GrantsProviderInterface::class, 'emagma_mcp_auth.token_grants_provider');

    $services->set('emagma_mcp_auth.metadata_controller', OAuthMetadataController::class)
        ->args([
            param('emagma_mcp_auth.authorization_servers'),
            param('emagma_mcp_auth.resource_path'),
            param('emagma_mcp_auth.scopes_supported'),
        ])
        ->tag('controller.service_arguments');
    // The routes name the controller by its class.
    $services->alias(OAuthMetadataController::class, 'emagma_mcp_auth.metadata_controller')
        ->public();

    $services->set('emagma_mcp_auth.entry_point', McpAuthenticationEntryPoint::class);
    $services->alias(McpAuthenticationEntryPoint::class, 'emagma_mcp_auth.entry_point');

    $services->set('emagma_mcp_auth.challenge_response_listener', McpChallengeResponseListener::class)
        ->args([
            param('emagma_mcp_auth.resource_path'),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onResponse']);
};
