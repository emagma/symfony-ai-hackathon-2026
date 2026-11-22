<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Logs the service account in and keeps its token in the shared cache until it expires.
 *
 * @internal
 */
final class JwtTokenProvider implements JwtTokenProviderInterface
{
    /** Subtracted from the token's `exp`: a request started just before it never carries an expired token. */
    private const int CACHE_SAFETY_MARGIN_S = 30;

    private const string CACHE_KEY_PREFIX = 'emagma_mcp_sylius.admin_api.jwt.';

    private ?string $token = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $email,
        private readonly string $password,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function getToken(): string
    {
        if (null !== $this->token) {
            return $this->token;
        }

        $cacheItem = $this->cache->getItem($this->cacheKey());
        if ($cacheItem->isHit()) {
            $cached = $cacheItem->get();
            if (\is_string($cached) && '' !== $cached) {
                return $this->token = $cached;
            }
        }

        $response = $this->http->request('POST', 'administrators/token', [
            'json' => [
                'email' => $this->email,
                'password' => $this->password,
            ],
        ]);

        $payload = $response->toArray();

        if (!isset($payload['token']) || !\is_string($payload['token'])) {
            throw new \RuntimeException('Sylius admin authentication did not return a "token" field.');
        }

        $token = $payload['token'];
        $ttl = $this->extractRemainingTtl($token);
        if ($ttl > 0) {
            $cacheItem->set($token)->expiresAfter($ttl);
            $this->cache->save($cacheItem);
        }

        return $this->token = $token;
    }

    public function invalidate(): void
    {
        $this->token = null;
        $this->cache->deleteItem($this->cacheKey());
    }

    /**
     * Per account: two providers with different identities never share an entry.
     */
    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . sha1($this->email);
    }

    /**
     * No signature check: the token comes straight from the shop's own API.
     */
    private function extractRemainingTtl(string $jwt): int
    {
        $parts = explode('.', $jwt);
        if (3 !== \count($parts)) {
            return 0;
        }
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $payloadJson) {
            return 0;
        }
        $payload = json_decode($payloadJson, associative: true);
        if (!\is_array($payload) || !isset($payload['exp']) || !\is_int($payload['exp'])) {
            return 0;
        }

        return $payload['exp'] - time() - self::CACHE_SAFETY_MARGIN_S;
    }
}
