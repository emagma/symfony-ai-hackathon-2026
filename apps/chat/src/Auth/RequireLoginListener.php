<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Auth;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Everything but the login flow needs a logged-in user. The answer to a write
 * confirmation is the exception: it must not touch the session (a turn may be
 * streaming on it), and is authorized by its unguessable id instead.
 *
 * The validated token is put in the request attributes for the controllers.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final class RequireLoginListener
{
    public const TOKEN_ATTRIBUTE = '_testchat_token';

    private const PUBLIC_PATHS = ['/login', '/oauth/callback', '/logout', '/healthz'];

    public function __construct(
        private readonly TokenSession $tokens,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!$event->isMainRequest() || \in_array($path, self::PUBLIC_PATHS, true) || str_starts_with($path, '/api/elicitations/')) {
            return;
        }

        $token = $this->tokens->fresh($request->getSession());
        if (null !== $token) {
            $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);

            return;
        }

        $event->setResponse(str_starts_with($path, '/api/')
            ? new JsonResponse(['error' => 'Not logged in.'], 401)
            : new RedirectResponse('/login'));
    }
}
