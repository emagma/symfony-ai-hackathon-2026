<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Emagma\SyliusAssistantTestChat\Auth\OAuthClient;
use Emagma\SyliusAssistantTestChat\Auth\TokenSession;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class OAuthController
{
    /**
     * Logins in flight, by state: a browser may start two at once (Chrome
     * preloading a typed URL loads "/" twice, each bouncing to /login), and a
     * single slot would let the second overwrite the state of the first.
     */
    private const PENDING = 'testchat.oauth_pending';

    private const MAX_PENDING = 5;

    private const PENDING_TTL = 600;

    public function __construct(
        private readonly OAuthClient $oauth,
        private readonly TokenSession $tokens,
    ) {
    }

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(Request $request): RedirectResponse
    {
        $authorization = $this->oauth->authorizationRequest();

        $pending = self::pending($request->getSession());
        $pending[$authorization['state']] = ['pkce' => $authorization['pkce'], 'at' => time()];
        $request->getSession()->set(self::PENDING, \array_slice($pending, -self::MAX_PENDING, preserve_keys: true));

        return new RedirectResponse($authorization['url']);
    }

    #[Route('/oauth/callback', name: 'oauth_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $session = $request->getSession();

        // Plain text: the error description comes from the query string, and
        // must never be rendered as HTML.
        if (null !== $request->query->get('error')) {
            return self::failure(\sprintf('Keycloak refused the login: %s', $request->query->getString('error_description', $request->query->getString('error'))));
        }

        $pending = self::pending($session);
        $state = $request->query->getString('state');
        $login = '' !== $state ? ($pending[$state] ?? null) : null;

        if (null === $login) {
            // A replayed or stale callback (back button, F5, a login started
            // more than 10 minutes ago). Already logged in: nothing to do.
            // Other logins in flight: this one lost the race, start over.
            // None at all: the session cookie did not survive the round trip,
            // and starting over would loop.
            return match (true) {
                null !== $this->tokens->get($session) => new RedirectResponse('/'),
                [] !== $pending => new RedirectResponse('/login'),
                default => self::failure('Invalid OAuth state, and no login in progress: is the session cookie blocked? Start again from /login.'),
            };
        }

        // Single use: every login in flight ends here, won or lost.
        $session->remove(self::PENDING);

        $this->tokens->store($session, $this->oauth->exchangeCode($request->query->getString('code'), $login['pkce']));
        $session->migrate(true);

        return new RedirectResponse('/');
    }

    /**
     * @return array<string, array{pkce: string, at: int}>
     */
    private static function pending(SessionInterface $session): array
    {
        $pending = $session->get(self::PENDING, []);
        $valid = [];
        foreach (\is_array($pending) ? $pending : [] as $state => $login) {
            if (\is_string($state) && \is_array($login) && \is_string($login['pkce'] ?? null) && \is_int($login['at'] ?? null) && $login['at'] > time() - self::PENDING_TTL) {
                $valid[$state] = ['pkce' => $login['pkce'], 'at' => $login['at']];
            }
        }

        return $valid;
    }

    private static function failure(string $message): Response
    {
        return new Response($message, 400, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(Request $request): RedirectResponse
    {
        $url = $this->oauth->logoutUrl($this->tokens->get($request->getSession()));
        $this->tokens->clear($request->getSession());

        return new RedirectResponse($url);
    }
}
