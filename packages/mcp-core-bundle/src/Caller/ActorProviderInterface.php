<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Caller;

/**
 * Extension point: who the current MCP call runs for, as recorded by the audit.
 * Return a stable identifier, never a display name or a secret: it is stored as given and readable with `mcp:core:audit:read`.
 */
interface ActorProviderInterface
{
    /**
     * Null when the call has no identifiable actor.
     */
    public function actor(): ?string;
}
