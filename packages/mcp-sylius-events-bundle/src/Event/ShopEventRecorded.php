<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Event;

/**
 * An event of the shop was just written to the journal: dispatched through the event dispatcher, synchronously,
 * inside the webhook's request. A webhook delivered again dispatches nothing. Whoever listens reacts at once
 * (wakes a background agent); what it reads is still the journal.
 */
final readonly class ShopEventRecorded
{
    public function __construct(
        /** The event's name, e.g. `customer.created`. */
        public string $name,
        /** The id of what the event is about, null when the shop sent none. */
        public ?string $subjectId,
        /** Its sequence in the journal (`ShopEvent::getSequence()`). */
        public int $sequence,
    ) {
    }
}
