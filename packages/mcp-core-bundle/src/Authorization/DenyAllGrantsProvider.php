<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * The default grants: none, so that no tool is exposed until the host wires a real provider.
 */
final readonly class DenyAllGrantsProvider implements GrantsProviderInterface
{
    public function grants(): Grants
    {
        return Grants::none();
    }
}
