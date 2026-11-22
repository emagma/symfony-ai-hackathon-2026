<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle;

use Emagma\McpCoreBundle\Authorization\Grants;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Trust boundary: written only by the firewall's access-token handler, when it validates the bearer.
 * Reset between requests, so that a caller's grants never leak into the next one on a long-lived worker.
 * Public API: a token handler of another identity provider injects it and calls `store()`.
 */
final class McpGrantsTokenStore implements ResetInterface
{
    private ?Grants $grants = null;

    public function store(Grants $grants): void
    {
        $this->grants = $grants;
    }

    public function grants(): Grants
    {
        return $this->grants ?? Grants::none();
    }

    public function reset(): void
    {
        $this->grants = null;
    }
}
