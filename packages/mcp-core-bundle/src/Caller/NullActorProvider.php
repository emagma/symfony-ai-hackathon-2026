<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Caller;

/**
 * No fallback to a service account on purpose: a name true of every entry identifies nobody.
 */
final readonly class NullActorProvider implements ActorProviderInterface
{
    public function actor(): ?string
    {
        return null;
    }
}
