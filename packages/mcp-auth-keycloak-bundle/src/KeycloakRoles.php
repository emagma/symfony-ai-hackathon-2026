<?php

declare(strict_types=1);

namespace Emagma\McpAuthKeycloakBundle;

/**
 * Reads claims of a bearer JWT. The signature is NOT checked: call only after Keycloak has vouched for the token,
 * for authorization only, never as proof of authentication.
 *
 * @internal
 */
final class KeycloakRoles
{
    /**
     * @return list<string>
     */
    public static function realmRoles(string $accessToken): array
    {
        $payload = self::payload($accessToken);
        if (!\is_array($payload['realm_access']['roles'] ?? null)) {
            return [];
        }

        return array_values(array_filter($payload['realm_access']['roles'], \is_string(...)));
    }

    /**
     * The `aud` claim, a string or a list in a JWT.
     *
     * @return list<string>
     */
    public static function audiences(string $accessToken): array
    {
        $audience = self::payload($accessToken)['aud'] ?? [];

        return array_values(array_filter(\is_array($audience) ? $audience : [$audience], \is_string(...)));
    }

    public static function claim(string $accessToken, string $name): ?string
    {
        $value = self::payload($accessToken)[$name] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @return array<mixed>
     */
    private static function payload(string $accessToken): array
    {
        $parts = explode('.', $accessToken);
        if (3 !== \count($parts)) {
            return [];
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $json) {
            return [];
        }
        $payload = json_decode($json, associative: true);

        return \is_array($payload) ? $payload : [];
    }
}
