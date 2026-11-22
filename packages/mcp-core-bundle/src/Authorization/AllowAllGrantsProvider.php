<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * Disables MCP authorization: an explicit opt-in for stdio, local development and tests, never a default.
 */
final readonly class AllowAllGrantsProvider implements GrantsProviderInterface
{
    public function grants(): Grants
    {
        return Grants::allowAll();
    }
}
