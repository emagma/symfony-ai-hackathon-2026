<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds the authorization server the way an MCP client does, with no Keycloak
 * URL configured here: the MCP server's protected-resource metadata (RFC 9728)
 * names the issuer, whose OpenID configuration names the endpoints. Exercising
 * that path is half the point of a test client.
 */
final class AuthorizationServerDiscovery
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire('%env(TESTCHAT_MCP_URL)%')]
        private readonly string $mcpUrl,
    ) {
    }

    public function discover(): AuthorizationServer
    {
        return $this->cache->get('testchat.authorization_server', function (ItemInterface $item): AuthorizationServer {
            $item->expiresAfter(3600);

            $parts = parse_url($this->mcpUrl);
            $origin = \sprintf('%s://%s%s', $parts['scheme'] ?? 'http', $parts['host'] ?? 'localhost', isset($parts['port']) ? ':' . $parts['port'] : '');

            /** @var array{authorization_servers?: list<string>} $resource */
            $resource = $this->httpClient->request('GET', $origin . '/.well-known/oauth-protected-resource')->toArray();
            $issuer = $resource['authorization_servers'][0]
                ?? throw new \RuntimeException('The MCP server advertises no authorization server (RFC 9728 metadata).');

            /** @var array{issuer: string, authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string, end_session_endpoint?: string} $openid */
            $openid = $this->httpClient->request('GET', rtrim($issuer, '/') . '/.well-known/openid-configuration')->toArray();

            return new AuthorizationServer(
                $openid['issuer'],
                $openid['authorization_endpoint'],
                $openid['token_endpoint'],
                $openid['userinfo_endpoint'],
                $openid['end_session_endpoint'] ?? null,
            );
        });
    }
}
