<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the OAuth 2.0 Protected Resource Metadata (RFC 9728) of the MCP endpoint.
 * Public by design, discovery precedes auth: the host's firewall must leave its routes open.
 *
 * @internal
 */
final readonly class OAuthMetadataController
{
    /**
     * @param list<string> $authorizationServers the issuers, as the CLIENT reaches them
     * @param list<string> $scopes
     */
    public function __construct(
        private array $authorizationServers,
        private string $resourcePath = '/_mcp',
        private array $scopes = ['openid', 'profile', 'email'],
    ) {
    }

    public function protectedResource(Request $request): JsonResponse
    {
        $response = new JsonResponse([
            'resource' => $request->getSchemeAndHttpHost() . $this->resourcePath,
            'authorization_servers' => $this->authorizationServers,
            'scopes_supported' => $this->scopes,
            'bearer_methods_supported' => ['header'],
        ]);
        // Discovery is cross-origin for browser-based clients; the payload is public.
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
