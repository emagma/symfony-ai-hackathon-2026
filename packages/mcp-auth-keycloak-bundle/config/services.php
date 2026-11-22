<?php

declare(strict_types=1);

use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpAuthKeycloakBundle\KeycloakAccessTokenHandler;
use Emagma\McpCoreBundle\Caller\McpCallerStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private();

    // An alias, so that a host can scope the client or a test suite replace it.
    $services->alias('emagma_mcp_auth_keycloak.http_client', 'http_client');

    $services->set('emagma_mcp_auth_keycloak.access_token_handler', KeycloakAccessTokenHandler::class)
        ->args([
            '$httpClient' => service('emagma_mcp_auth_keycloak.http_client'),
            '$grantsStore' => service(McpGrantsTokenStore::class),
            '$keycloakUserinfoUrl' => param('emagma_mcp_auth_keycloak.userinfo_url'),
            '$callerStore' => service(McpCallerStore::class),
            '$audience' => param('emagma_mcp_auth_keycloak.audience'),
        ]);

    // The host's firewall names the handler by its class.
    $services->alias(KeycloakAccessTokenHandler::class, 'emagma_mcp_auth_keycloak.access_token_handler');
};
