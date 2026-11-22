<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpEventPlugin\Relay;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Webhook\Messenger\SendWebhookMessage;
use Symfony\Component\Webhook\Subscriber;

/**
 * Sends an event to the MCP server with Symfony Webhook: signed with the shared secret, through Messenger so
 * that the shop never waits for the server. Without a URL or a secret nothing is sent.
 */
final readonly class ShopEventRelay
{
    public function __construct(
        private MessageBusInterface $bus,
        private ?string $url,
        #[\SensitiveParameter]
        private ?string $secret,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->url && '' !== $this->url && null !== $this->secret && '' !== $this->secret;
    }

    /**
     * @param array<string, scalar|null> $payload identifiers and dates only: the server reads the rest
     *                                            through the Admin API, under its own rules
     */
    public function relay(string $name, array $payload): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        \assert(null !== $this->url && null !== $this->secret);

        $this->bus->dispatch(new SendWebhookMessage(
            new Subscriber($this->url, $this->secret),
            new RemoteEvent($name, Uuid::v7()->toRfc4122(), $payload),
        ));
    }
}
