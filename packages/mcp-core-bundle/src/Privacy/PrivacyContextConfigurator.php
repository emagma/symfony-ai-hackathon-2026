<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Privacy;

use Emagma\McpCoreBundle\Caller\McpCallerStore;
use Mcp\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sets the privacy mode of the caller's OAuth client on the SDK's request event, not on `kernel.request`:
 * the client id is known only once the firewall validated the token.
 *
 * @internal
 */
final class PrivacyContextConfigurator implements ResetInterface
{
    /**
     * @param array<string, array{mode: string, reveal: bool}> $clients by OAuth client id
     */
    public function __construct(
        private readonly McpCallerStore $callers,
        private readonly string $defaultMode,
        private readonly array $clients,
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $this->apply();
    }

    public function apply(): void
    {
        $client = $this->callers->clientId();
        $settings = null !== $client ? ($this->clients[$client] ?? null) : null;

        PrivacyContext::configure(
            PrivacyMode::from($settings['mode'] ?? $this->defaultMode),
            new Pseudonymiser($this->secret),
            $settings['reveal'] ?? true,
        );
    }

    public function reset(): void
    {
        PrivacyContext::reset();
    }
}
