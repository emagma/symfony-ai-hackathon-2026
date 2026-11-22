<?php

declare(strict_types=1);

namespace Emagma\McpAuthKeycloakBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The host names KeycloakAccessTokenHandler as the `token_handler` of the `access_token` authenticator of its MCP firewall.
 */
final class EmagmaMcpAuthKeycloakBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('userinfo_url')
                    ->info(
                        'The userinfo endpoint of the realm, as the SERVER reaches it (its own network, not the'
                        . ' public URL): `<keycloak>/realms/<realm>/protocol/openid-connect/userinfo`. A 200 from'
                        . ' it is what proves a bearer genuine and active. `MCP_AUTH_USERINFO_URL` by default.',
                    )
                    ->cannotBeEmpty()
                    ->defaultValue('%env(MCP_AUTH_USERINFO_URL)%')
                ->end()
                ->scalarNode('audience')
                    ->info(
                        'The audience (`aud` claim) a token must carry to be accepted: the name the realm gives this'
                        . ' server, which a mapper of the realm adds to the tokens of the clients meant for it.'
                        . ' Several MCP servers on one realm each refuse the tokens of the others. Empty accepts any'
                        . ' genuine token of the realm. `MCP_AUTH_AUDIENCE` by default, empty when the variable is'
                        . ' not set.',
                    )
                    ->defaultValue('%env(default::MCP_AUTH_AUDIENCE)%')
                ->end()
            ->end()
        ;
    }

    /**
     * @param array{userinfo_url: string, audience: string|null} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('emagma_mcp_auth_keycloak.userinfo_url', $config['userinfo_url'])
            ->set('emagma_mcp_auth_keycloak.audience', $config['audience'])
        ;

        $container->import('../config/services.php');
    }
}
