<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Caller;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * The actor is the identifier of the authenticated user, trimmed and cut to the 255 characters the audit stores.
 */
final readonly class SecurityActorProvider implements ActorProviderInterface
{
    private const int MAX_LENGTH = 255;

    public function __construct(private Security $security)
    {
    }

    public function actor(): ?string
    {
        $user = $this->security->getUser();
        if (null === $user) {
            return null;
        }

        $identifier = trim($user->getUserIdentifier());
        if ('' === $identifier) {
            return null;
        }

        // The audit column is bounded, and an audit write must not throw.
        return mb_substr($identifier, 0, self::MAX_LENGTH);
    }
}
