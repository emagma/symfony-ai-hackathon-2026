<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The only thing the chat keeps in the Symfony session: the user's tokens.
 *
 * Keycloak access tokens live 5 minutes; fresh() renews one that is about to
 * expire before a turn starts, so the turn - which releases the session before
 * streaming - never has to write it back.
 */
final class TokenSession
{
    private const KEY = 'testchat.user_token';

    /**
     * A turn cannot renew the token once it streams: leave it room for a slow
     * model, tool loops and a 30 s confirmation or two.
     */
    public const MIN_TTL = 240;

    public function __construct(
        private readonly OAuthClient $oauth,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function get(SessionInterface $session): ?UserToken
    {
        $token = $session->get(self::KEY);

        return $token instanceof UserToken ? $token : null;
    }

    public function store(SessionInterface $session, UserToken $token): void
    {
        $session->set(self::KEY, $token);
    }

    public function clear(SessionInterface $session): void
    {
        $session->invalidate();
    }

    /**
     * The token, renewed when less than MIN_TTL seconds remain; null when the
     * user is not logged in or the refresh failed (session over).
     */
    public function fresh(SessionInterface $session): ?UserToken
    {
        $token = $this->get($session);
        if (null === $token || !$token->expiresWithin(self::MIN_TTL)) {
            return $token;
        }

        try {
            $token = $this->oauth->refresh($token);
        } catch (\Throwable $exception) {
            $this->logger?->warning('The access token could not be renewed: the user must log in again.', ['exception' => $exception]);
            $session->remove(self::KEY);

            return null;
        }

        $this->store($session, $token);

        return $token;
    }
}
