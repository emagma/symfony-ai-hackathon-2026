<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle;

use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;

/**
 * The grants of the token the firewall validated; none without one, no open fallback.
 *
 * @internal
 */
final readonly class TokenGrantsProvider implements GrantsProviderInterface
{
    public function __construct(
        private McpGrantsTokenStore $store,
    ) {
    }

    public function grants(): Grants
    {
        return $this->store->grants();
    }
}
