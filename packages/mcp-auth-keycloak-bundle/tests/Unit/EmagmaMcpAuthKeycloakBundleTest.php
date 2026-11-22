<?php

declare(strict_types=1);

namespace Emagma\McpAuthKeycloakBundle\Tests\Unit;

use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpAuthKeycloakBundle\EmagmaMcpAuthKeycloakBundle;
use Emagma\McpAuthKeycloakBundle\KeycloakAccessTokenHandler;
use Emagma\McpCoreBundle\Caller\McpCallerStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(EmagmaMcpAuthKeycloakBundle::class)]
final class EmagmaMcpAuthKeycloakBundleTest extends TestCase
{
    public function testItsAliasIsDerivedFromItsVendorPrefixedName(): void
    {
        self::assertSame('emagma_mcp_auth_keycloak', $this->extension()->getAlias());
    }

    public function testTheHandlerChecksBearersAtTheConfiguredUserinfoEndpoint(): void
    {
        $container = $this->load(['userinfo_url' => 'http://keycloak/realms/shop/protocol/openid-connect/userinfo']);

        $handler = $container->getDefinition('emagma_mcp_auth_keycloak.access_token_handler');
        self::assertSame(KeycloakAccessTokenHandler::class, $handler->getClass());
        self::assertSame('http://keycloak/realms/shop/protocol/openid-connect/userinfo', $container->getParameter('emagma_mcp_auth_keycloak.userinfo_url'));
        self::assertEquals(new Reference('emagma_mcp_auth_keycloak.http_client'), $handler->getArgument('$httpClient'));
        self::assertEquals(new Reference(McpGrantsTokenStore::class), $handler->getArgument('$grantsStore'));
        self::assertEquals(new Reference(McpCallerStore::class), $handler->getArgument('$callerStore'));
        self::assertSame('http_client', (string) $container->getAlias('emagma_mcp_auth_keycloak.http_client'));
    }

    public function testTheHostsFirewallReachesTheHandlerByItsClass(): void
    {
        $container = $this->load([]);

        self::assertSame('emagma_mcp_auth_keycloak.access_token_handler', (string) $container->getAlias(KeycloakAccessTokenHandler::class));
    }

    public function testTheUserinfoEndpointAndTheAudienceComeFromTheEnvironmentUnlessConfigured(): void
    {
        $container = $this->load([]);

        self::assertSame('%env(MCP_AUTH_USERINFO_URL)%', $container->getParameter('emagma_mcp_auth_keycloak.userinfo_url'));
        self::assertSame('%env(default::MCP_AUTH_AUDIENCE)%', $container->getParameter('emagma_mcp_auth_keycloak.audience'), 'no audience unless the variable names one');
    }

    public function testAnEmptyUserinfoEndpointIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['userinfo_url' => '']);
    }

    public function testItsServicesAreNamedAfterTheAlias(): void
    {
        foreach (array_diff(array_keys($this->load([])->getDefinitions()), ['service_container']) as $id) {
            self::assertStringStartsWith('emagma_mcp_auth_keycloak.', $id);
        }
    }

    private function extension(): ExtensionInterface
    {
        $extension = (new EmagmaMcpAuthKeycloakBundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);

        return $extension;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
        $this->extension()->load([$config], $container);

        return $container;
    }
}
