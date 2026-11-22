<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Caller;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The OAuth client (`azp` claim) of the current request, filled by the host's access-token handler.
 * It decides the privacy mode because the identity provider signs it, where `clientInfo.name` is whatever the client says.
 */
final class McpCallerStore implements ResetInterface
{
    private ?string $clientId = null;

    public function store(?string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function clientId(): ?string
    {
        return $this->clientId;
    }

    public function reset(): void
    {
        $this->clientId = null;
    }
}
