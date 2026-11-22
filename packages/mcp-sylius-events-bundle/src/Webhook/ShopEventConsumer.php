<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Webhook;

use Emagma\McpSyliusEventsBundle\Entity\ShopEvent;
use Emagma\McpSyliusEventsBundle\Event\ShopEventRecorded;
use Emagma\McpSyliusEventsBundle\Store\ShopEventStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Symfony Webhook has checked the signature: the event is written to the journal, then `ShopEventRecorded` says
 * so. Whoever reacts to it (a background agent) is woken by that event and reads the journal at its own pace.
 *
 * @internal
 */
final readonly class ShopEventConsumer implements ConsumerInterface
{
    /** The name of the webhook in `framework.webhook.routing`, hence its route: /webhook/sylius. */
    public const string WEBHOOK = 'sylius';

    public function __construct(
        private ShopEventStoreInterface $store,
        private ClockInterface $clock,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        $payload = $event->getPayload();
        $subject = $payload['id'] ?? null;

        $recorded = new ShopEvent(
            $event->getId(),
            $event->getName(),
            \is_string($subject) || \is_int($subject) ? (string) $subject : null,
            $payload,
            $this->clock->now(),
        );
        if (!$this->store->record($recorded)) {
            // Delivered again: it was said the first time.
            return;
        }

        $this->dispatcher->dispatch(new ShopEventRecorded($recorded->getName(), $recorded->getSubjectId(), $recorded->getSequence()));
    }
}
