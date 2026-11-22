<?php

declare(strict_types=1);

namespace Emagma\McpAuthKeycloakBundle;

use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Caller\McpCallerStore;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A 200 from Keycloak's userinfo endpoint proves the bearer genuine and active; the audience, when one is
 * configured, that it was issued for this server.
 * The only writer of the grants store: grants always originate from a firewall-validated token.
 */
final class KeycloakAccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly McpGrantsTokenStore $grantsStore,
        private readonly string $keycloakUserinfoUrl,
        private readonly McpCallerStore $callerStore = new McpCallerStore(),
        private readonly ?string $audience = null,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $claims = $this->httpClient
                ->request('GET', $this->keycloakUserinfoUrl, ['auth_bearer' => $accessToken])
                ->toArray();
        } catch (\Throwable $exception) {
            throw new BadCredentialsException('Invalid Keycloak access token.', previous: $exception);
        }

        $email = $claims['email'] ?? null;
        if (!\is_string($email) || '' === $email) {
            throw new BadCredentialsException('Keycloak token is missing the "email" claim.');
        }
        $sub = $claims['sub'] ?? null;
        if (!\is_string($sub) || '' === $sub) {
            throw new BadCredentialsException('Keycloak userinfo is missing the "sub" claim.');
        }

        // A token minted for another resource of the realm is genuine and active, yet not for this server.
        if (null !== $this->audience && '' !== $this->audience && !\in_array($this->audience, KeycloakRoles::audiences($accessToken), true)) {
            throw new BadCredentialsException(\sprintf('The token is not issued for this server (audience "%s" expected).', $this->audience));
        }

        // userinfo does not carry realm roles: read from the validated token.
        $realmRoles = KeycloakRoles::realmRoles($accessToken);

        $this->grantsStore->store(Grants::fromStrings(self::grantStrings($realmRoles)));
        // The client the token was issued to decides the privacy mode (emagma_mcp_core.privacy.clients).
        $this->callerStore->store(KeycloakRoles::claim($accessToken, 'azp'));

        return new UserBadge($sub, static fn (): KeycloakUser => new KeycloakUser($sub, $email));
    }

    /**
     * Deliberately no mapping from a business role to a wildcard: only `mcp:*` roles grant,
     * so that widening a profile is an explicit edit of the realm (composite roles).
     *
     * @param list<string> $realmRoles
     *
     * @return list<string>
     */
    private static function grantStrings(array $realmRoles): array
    {
        return array_values(array_filter(
            $realmRoles,
            static fn (string $role): bool => str_starts_with($role, 'mcp:'),
        ));
    }
}
