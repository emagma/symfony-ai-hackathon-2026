<?php

declare(strict_types=1);

namespace Emagma\McpAuthKeycloakBundle;

use Emagma\McpCoreBundle\Caller\SecurityActorProvider;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The identifier is the OIDC `sub`, not the email: stable, opaque, and what the audit names as actor
 * (through `SecurityActorProvider`). The one role grants nothing: the grants decide.
 *
 * @internal
 */
final readonly class KeycloakUser implements UserInterface
{
    public function __construct(
        private string $sub,
        private string $email,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->sub;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_MCP_USER'];
    }

    /**
     * Nothing to erase: the user holds no credential. Removed from UserInterface in Symfony 8; the attribute
     * tells Symfony 7.4 not to call it.
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
