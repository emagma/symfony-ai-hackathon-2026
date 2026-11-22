<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Testing;

use Emagma\McpSyliusEventsBundle\Entity\ShopEvent;
use Emagma\McpSyliusEventsBundle\Store\ShopEventStoreInterface;

/**
 * The store of the test suites. Events get their sequence here, as the database would give it.
 */
final class InMemoryShopEventStore implements ShopEventStoreInterface
{
    /** @var list<ShopEvent> */
    private array $events = [];

    public function record(ShopEvent $event): bool
    {
        foreach ($this->events as $known) {
            if ($known->getEventId() === $event->getEventId()) {
                return false;
            }
        }
        $this->events[] = $event;
        (new \ReflectionProperty(ShopEvent::class, 'id'))->setValue($event, \count($this->events));

        return true;
    }

    public function after(string $name, int $sequence, int $limit = 50): array
    {
        return \array_slice(array_values(array_filter(
            $this->events,
            static fn (ShopEvent $event): bool => $event->getName() === $name && $event->getSequence() > $sequence,
        )), 0, $limit);
    }
}
