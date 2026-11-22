<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Auth;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authorization code + PKCE S256 against the discovered Keycloak, with the
 * public client `mcp-testchat` (.castor/keycloak-realm.php).
 */
final class OAuthClient
{
    public const CLIENT_ID = 'mcp-testchat';

    public function __construct(
        private readonly AuthorizationServerDiscovery $discovery,
        #[Autowire('%env(TESTCHAT_PUBLIC_URL)%')]
        private readonly string $publicUrl,
    ) {
    }

    /**
     * @return array{url: string, state: string, pkce: string}
     */
    public function authorizationRequest(): array
    {
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl(['scope' => 'openid profile email']);

        return ['url' => $url, 'state' => $provider->getState(), 'pkce' => (string) $provider->getPkceCode()];
    }

    public function exchangeCode(string $code, string $pkce): UserToken
    {
        $provider = $this->provider();
        $provider->setPkceCode($pkce);

        return $this->toUserToken($provider->getAccessToken('authorization_code', ['code' => $code]), null);
    }

    public function refresh(UserToken $token): UserToken
    {
        if (null === $token->refreshToken) {
            throw new \RuntimeException('The session has no refresh token: log in again.');
        }

        return $this->toUserToken($this->provider()->getAccessToken('refresh_token', ['refresh_token' => $token->refreshToken]), $token);
    }

    public function logoutUrl(?UserToken $token): string
    {
        $endpoint = $this->discovery->discover()->endSessionEndpoint;
        if (null === $endpoint) {
            return $this->publicUrl . '/';
        }

        return $endpoint . '?' . http_build_query(array_filter([
            'client_id' => self::CLIENT_ID,
            'post_logout_redirect_uri' => $this->publicUrl . '/',
            'id_token_hint' => $token?->idToken,
        ]));
    }

    private function provider(): GenericProvider
    {
        $server = $this->discovery->discover();

        return new GenericProvider([
            'clientId' => self::CLIENT_ID,
            'redirectUri' => $this->publicUrl . '/oauth/callback',
            'urlAuthorize' => $server->authorizationEndpoint,
            'urlAccessToken' => $server->tokenEndpoint,
            'urlResourceOwnerDetails' => $server->userinfoEndpoint,
            'scopeSeparator' => ' ',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ]);
    }

    /**
     * The id_token comes straight from the token endpoint over the back
     * channel, so its claims are read without re-verifying the signature
     * (OIDC Core §3.1.3.7) - and only for display and attribution.
     */
    private function toUserToken(AccessTokenInterface $token, ?UserToken $previous): UserToken
    {
        \assert($token instanceof AccessToken);
        $values = $token->getValues();
        $idToken = \is_string($values['id_token'] ?? null) ? $values['id_token'] : $previous?->idToken;
        $claims = self::claims($idToken ?? $token->getToken());

        return new UserToken(
            $token->getToken(),
            $token->getRefreshToken() ?? $previous?->refreshToken,
            $token->getExpires() ?? time() + 60,
            \is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : ($previous->username ?? 'unknown'),
            $idToken,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function claims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1] ?? '';
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        return \is_array($claims) ? array_filter($claims, \is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }
}
