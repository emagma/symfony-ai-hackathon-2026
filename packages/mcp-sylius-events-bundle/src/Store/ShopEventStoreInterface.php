<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Store;

use Emagma\McpSyliusEventsBundle\Entity\ShopEvent;

/**
 * Where the received shop events are kept, and read by the agents at their own pace.
 */
interface ShopEventStoreInterface
{
    /**
     * False when the event was already recorded (the shop delivers a webhook again after a timeout).
     */
    public function record(ShopEvent $event): bool;

    /**
     * The events of a name received after a sequence, the oldest first.
     *
     * @return list<ShopEvent>
     */
    public function after(string $name, int $sequence, int $limit = 50): array;
}
