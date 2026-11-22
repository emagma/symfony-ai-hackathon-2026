<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * Extension point: an IdP adapter translates its own entitlements into Grants.
 * Bound to DenyAllGrantsProvider until the host wires a real provider: no tool is exposed.
 */
interface GrantsProviderInterface
{
    public function grants(): Grants;
}
