<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\EventListener;

use Emagma\McpAuthBundle\McpAuthenticationEntryPoint;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Adds the RFC 9728 pointer to any 401 of the endpoint that lacks it.
 * An invalid or expired token fails inside the access_token authenticator, whose 401 has none.
 *
 * @internal
 */
final readonly class McpChallengeResponseListener
{
    public function __construct(
        private string $resourcePath = '/_mcp',
    ) {
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), $this->resourcePath)) {
            return;
        }

        $response = $event->getResponse();
        if (Response::HTTP_UNAUTHORIZED !== $response->getStatusCode()) {
            return;
        }

        $header = (string) $response->headers->get('WWW-Authenticate', '');
        if (str_contains($header, 'resource_metadata=')) {
            return;
        }

        $pointer = \sprintf(
            'resource_metadata="%s"',
            $request->getSchemeAndHttpHost() . McpAuthenticationEntryPoint::METADATA_PATH,
        );
        $response->headers->set('WWW-Authenticate', '' === $header ? 'Bearer ' . $pointer : $header . ', ' . $pointer);
    }
}
