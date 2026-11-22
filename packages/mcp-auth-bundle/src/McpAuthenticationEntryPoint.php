<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The 401 of RFC 9728 §5.3, pointing a remote client at the protected-resource metadata.
 * The host names this service as the `entry_point:` of the firewall that guards the endpoint (public API).
 */
final class McpAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    /** Must match the route of config/routes.yaml. */
    public const string METADATA_PATH = '/.well-known/oauth-protected-resource';

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['error' => 'unauthorized', 'error_description' => 'Authorization required to call the MCP endpoint.'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => \sprintf('Bearer resource_metadata="%s"', $request->getSchemeAndHttpHost() . self::METADATA_PATH)],
        );
    }
}
