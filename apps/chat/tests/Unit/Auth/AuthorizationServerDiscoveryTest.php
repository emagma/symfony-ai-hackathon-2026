<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Auth;

use Emagma\SyliusAssistantTestChat\Auth\AuthorizationServerDiscovery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(AuthorizationServerDiscovery::class)]
final class AuthorizationServerDiscoveryTest extends TestCase
{
    /**
     * RFC 9728 then OIDC discovery: the only URL configured is the MCP endpoint.
     */
    public function testTheIssuerComesFromTheMcpServersProtectedResourceMetadata(): void
    {
        $requested = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requested): JsonMockResponse {
            $requested[] = $url;

            return match ($url) {
                'http://shop/.well-known/oauth-protected-resource' => new JsonMockResponse(['authorization_servers' => ['http://keycloak:8085/realms/sylius']]),
                'http://keycloak:8085/realms/sylius/.well-known/openid-configuration' => new JsonMockResponse([
                    'issuer' => 'http://keycloak:8085/realms/sylius',
                    'authorization_endpoint' => 'http://keycloak:8085/auth',
                    'token_endpoint' => 'http://keycloak:8085/token',
                    'userinfo_endpoint' => 'http://keycloak:8085/userinfo',
                    'end_session_endpoint' => 'http://keycloak:8085/logout',
                ]),
                default => new JsonMockResponse([], ['http_code' => 404]),
            };
        });

        $discovery = new AuthorizationServerDiscovery($client, new ArrayAdapter(), 'http://shop/_mcp');
        $server = $discovery->discover();
        $discovery->discover();

        self::assertSame('http://keycloak:8085/token', $server->tokenEndpoint);
        self::assertSame('http://keycloak:8085/logout', $server->endSessionEndpoint);
        self::assertCount(2, $requested, 'discovered once, then cached');
    }
}
