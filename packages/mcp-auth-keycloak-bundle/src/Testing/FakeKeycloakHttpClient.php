<?php

declare(strict_types=1);

namespace Emagma\McpAuthKeycloakBundle\Testing;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for Keycloak's userinfo endpoint in a test environment: alias `emagma_mcp_auth_keycloak.http_client` to it.
 * A bearer is valid when token() forged it.
 * Public API for the test suites of a host: needs symfony/http-client.
 */
final class FakeKeycloakHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(static function (string $method, string $url, array $options): ResponseInterface {
            $headers = $options['normalized_headers'] ?? null;
            $authorization = \is_array($headers) ? ($headers['authorization'] ?? null) : null;
            $header = \is_array($authorization) && \is_string($authorization[0] ?? null) ? $authorization[0] : '';
            $token = substr($header, \strlen('Authorization: Bearer '));
            $email = self::emailOf($token);

            if (null === $email) {
                return new MockResponse('{"error":"invalid_token"}', ['http_code' => 401]);
            }

            return new MockResponse(json_encode(['sub' => self::subOf($email), 'email' => $email], \JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });
    }

    /**
     * Distinct from the email, so that a test can tell which one an audit row carries.
     */
    public static function subOf(string $email): string
    {
        return 'kc-' . substr(sha1($email), 0, 12);
    }

    /**
     * @param list<string> $realmRoles
     * @param list<string> $audiences
     */
    public static function token(string $email, array $realmRoles, string $clientId = 'mcp-llm', array $audiences = ['account']): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none', 'typ' => 'JWT'])
            . '.' . $encode(['email' => $email, 'azp' => $clientId, 'aud' => $audiences, 'realm_access' => ['roles' => $realmRoles], 'fake' => true])
            . '.' . 'unsigned';
    }

    private static function emailOf(string $token): ?string
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts)) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return \is_array($payload) && true === ($payload['fake'] ?? false) && \is_string($payload['email'] ?? null)
            ? $payload['email']
            : null;
    }
}
