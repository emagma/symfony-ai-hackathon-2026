<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Privacy;

/**
 * Tokens such as `email:7f3a9c21`: stable so the model can relate records, keyed so they cannot be computed from outside.
 * The value is normalised (lowercased, spaces removed) so a differently-typed phone number maps to the same token.
 */
final readonly class Pseudonymiser
{
    public function __construct(#[\SensitiveParameter] private string $secret)
    {
        // An empty key would make every token computable from outside.
        if ('' === $secret) {
            throw new \InvalidArgumentException('emagma_mcp_core.privacy.secret is empty: set MCP_PRIVACY_SECRET (or APP_SECRET, its default).');
        }
    }

    public function tokenFor(string $property, string $value): string
    {
        $normalised = mb_strtolower(preg_replace('/\s+/u', '', $value) ?? $value);

        return self::kindOf($property) . ':' . substr(hash_hmac('sha256', $normalised, $this->secret), 0, 8);
    }

    private static function kindOf(string $property): string
    {
        $name = mb_strtolower($property);

        return match (true) {
            str_contains($name, 'mail') => 'email',
            str_contains($name, 'phone') => 'phone',
            str_contains($name, 'user') || str_contains($name, 'login') => 'login',
            default => 'id',
        };
    }
}
