<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Caller;

use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\RequestMeta;

/**
 * The declared `clientInfo`, from the handshake's session or, without a handshake (2026-07-28), the request's `_meta`.
 * What the handshake stored wins: a later request cannot rename its client through `_meta`.
 */
final readonly class ClientInfo
{
    private function __construct(
        public ?string $name,
        public ?string $version,
    ) {
    }

    public static function of(SessionInterface $session): self
    {
        $info = $session->get('client_info');
        if (!\is_array($info)) {
            $meta = $session->get(Protocol::SESSION_ACTIVE_REQUEST_META);
            $info = \is_array($meta) ? ($meta[RequestMeta::CLIENT_INFO] ?? null) : null;
        }
        if (!\is_array($info)) {
            return new self(null, null);
        }

        $name = $info['name'] ?? null;
        $version = $info['version'] ?? null;

        return new self(
            \is_string($name) && '' !== $name ? $name : null,
            \is_string($version) && '' !== $version ? $version : null,
        );
    }
}
