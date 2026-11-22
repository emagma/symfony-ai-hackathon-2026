<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The host declares the firewall (McpAuthenticationEntryPoint as `entry_point`), leaves the metadata routes
 * public and imports `@EmagmaMcpAuthBundle/config/routes.yaml`.
 * Register it after EmagmaMcpCoreBundle, whose deny-all grants it replaces.
 */
final class EmagmaMcpAuthBundle extends AbstractBundle
{
    /**
     * The package root, so that `@EmagmaMcpAuthBundle/config/routes.yaml` resolves.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('authorization_servers')
                    ->info(
                        'The issuers whose access tokens the endpoint accepts, as the REMOTE client reaches them'
                        . ' (a public URL, not the one the server uses on its own network). The client runs'
                        . ' Authorization Server metadata discovery against each. `MCP_AUTH_ISSUER_URL` by default.',
                    )
                    ->requiresAtLeastOneElement()
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(['%env(MCP_AUTH_ISSUER_URL)%'])
                ->end()
                ->scalarNode('resource_path')
                    ->info('The path of the MCP endpoint: the protected resource the metadata describes, and the one whose 401s carry the pointer to it.')
                    ->cannotBeEmpty()
                    ->defaultValue('/_mcp')
                    ->validate()
                        ->ifTrue(static fn (mixed $path): bool => !\is_string($path) || !str_starts_with($path, '/'))
                        ->thenInvalid('emagma_mcp_auth.resource_path must be an absolute path such as "/_mcp".')
                    ->end()
                ->end()
                ->arrayNode('scopes_supported')
                    ->info('The scopes the metadata advertises.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['openid', 'profile', 'email'])
                ->end()
            ->end()
        ;
    }

    /**
     * @param array{authorization_servers: list<string>, resource_path: string, scopes_supported: list<string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Read by config/routes.yaml and by the services.
        $container->parameters()
            ->set('emagma_mcp_auth.authorization_servers', $config['authorization_servers'])
            ->set('emagma_mcp_auth.resource_path', $config['resource_path'])
            ->set('emagma_mcp_auth.scopes_supported', $config['scopes_supported'])
        ;

        $container->import('../config/services.php');
    }
}
