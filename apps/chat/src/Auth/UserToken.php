<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Auth;

/**
 * The logged-in user's tokens, as kept in the session.
 */
final readonly class UserToken
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public int $expiresAt,
        public string $username,
        public ?string $idToken,
    ) {
    }

    public function expiresWithin(int $seconds, ?int $now = null): bool
    {
        return $this->expiresAt - ($now ?? time()) < $seconds;
    }
}
