<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\Tests\Unit;

use Emagma\McpAuthBundle\Controller\OAuthMetadataController;
use Emagma\McpAuthBundle\EmagmaMcpAuthBundle;
use Emagma\McpAuthBundle\EventListener\McpChallengeResponseListener;
use Emagma\McpAuthBundle\McpAuthenticationEntryPoint;
use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpAuthBundle\TokenGrantsProvider;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(EmagmaMcpAuthBundle::class)]
final class EmagmaMcpAuthBundleTest extends TestCase
{
    public function testItsAliasIsDerivedFromItsVendorPrefixedName(): void
    {
        self::assertSame('emagma_mcp_auth', $this->extension()->getAlias());
    }

    public function testTheGrantsBecomeThoseOfTheValidatedToken(): void
    {
        $container = $this->load(['authorization_servers' => ['https://idp']]);

        self::assertSame('emagma_mcp_auth.token_grants_provider', (string) $container->getAlias(GrantsProviderInterface::class));
        self::assertSame(TokenGrantsProvider::class, $container->getDefinition('emagma_mcp_auth.token_grants_provider')->getClass());
    }

    public function testTheMetadataAndTheChallengeFollowTheConfiguration(): void
    {
        $container = $this->load(['authorization_servers' => ['https://idp'], 'resource_path' => '/mcp', 'scopes_supported' => ['openid']]);

        self::assertSame(['https://idp'], $container->getParameter('emagma_mcp_auth.authorization_servers'));
        self::assertSame('/mcp', $container->getParameter('emagma_mcp_auth.resource_path'), 'read by the path-inserted metadata route');
        self::assertSame(['openid'], $container->getParameter('emagma_mcp_auth.scopes_supported'));
        self::assertSame(
            ['%emagma_mcp_auth.authorization_servers%', '%emagma_mcp_auth.resource_path%', '%emagma_mcp_auth.scopes_supported%'],
            array_map('strval', $container->getDefinition('emagma_mcp_auth.metadata_controller')->getArguments()),
        );
        self::assertSame('%emagma_mcp_auth.resource_path%', (string) $container->getDefinition('emagma_mcp_auth.challenge_response_listener')->getArgument(0));
    }

    public function testTheEndpointIsAtSlashMcpUnlessToldOtherwise(): void
    {
        $container = $this->load(['authorization_servers' => ['https://idp']]);

        self::assertSame('/_mcp', $container->getParameter('emagma_mcp_auth.resource_path'));
        self::assertSame(['openid', 'profile', 'email'], $container->getParameter('emagma_mcp_auth.scopes_supported'));
    }

    public function testTheIssuerComesFromTheEnvironmentUnlessConfigured(): void
    {
        self::assertSame(['%env(MCP_AUTH_ISSUER_URL)%'], $this->load([])->getParameter('emagma_mcp_auth.authorization_servers'));
    }

    public function testAnAuthorizationServerIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['authorization_servers' => []]);
    }

    public function testARelativeResourcePathIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('absolute path');

        $this->load(['authorization_servers' => ['https://idp'], 'resource_path' => 'mcp']);
    }

    public function testItsServicesAreNamedAfterTheAliasAndReachedByTheirClasses(): void
    {
        $container = $this->load([]);

        foreach (array_diff(array_keys($container->getDefinitions()), ['service_container']) as $id) {
            self::assertStringStartsWith('emagma_mcp_auth.', $id);
        }

        $aliased = [
            McpGrantsTokenStore::class => 'emagma_mcp_auth.grants_token_store',
            McpAuthenticationEntryPoint::class => 'emagma_mcp_auth.entry_point',
            OAuthMetadataController::class => 'emagma_mcp_auth.metadata_controller',
        ];
        foreach ($aliased as $class => $id) {
            self::assertSame($id, (string) $container->getAlias($class));
        }
        self::assertTrue($container->getAlias(OAuthMetadataController::class)->isPublic(), 'the routes name the controller by its class');
        self::assertSame(McpChallengeResponseListener::class, $container->getDefinition('emagma_mcp_auth.challenge_response_listener')->getClass());
    }

    public function testTheStoreIsResetBetweenRequests(): void
    {
        $tags = $this->load([])->getDefinition('emagma_mcp_auth.grants_token_store')->getTag('kernel.reset');

        self::assertSame([['method' => 'reset']], $tags);
    }

    private function extension(): ExtensionInterface
    {
        $extension = (new EmagmaMcpAuthBundle())->getContainerExtension();
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
