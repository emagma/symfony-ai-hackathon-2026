<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Auth;

/**
 * The OAuth endpoints the chat logs in against, as discovered from the MCP server.
 */
final readonly class AuthorizationServer
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $userinfoEndpoint,
        public ?string $endSessionEndpoint,
    ) {
    }
}
