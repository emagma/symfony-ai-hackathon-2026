<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Something that happened in the shop, as the event plugin told it: a name, the id of what it is about, when.
 * Never the data itself: whoever reads an event fetches the subject through the Admin API.
 * Not final: Doctrine builds its proxies by inheritance.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mcp_shop_event')]
#[ORM\UniqueConstraint(name: 'uniq_mcp_shop_event_event_id', columns: ['event_id'])]
#[ORM\Index(name: 'idx_mcp_shop_event_name', columns: ['name', 'id'])]
class ShopEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        /** The id the shop gave the event: a webhook delivered twice is recorded once. */
        #[ORM\Column(name: 'event_id', type: 'string', length: 64)]
        private readonly string $eventId,
        #[ORM\Column(type: 'string', length: 64)]
        private readonly string $name,
        #[ORM\Column(name: 'subject_id', type: 'string', length: 64, nullable: true)]
        private readonly ?string $subjectId,
        #[ORM\Column(type: 'json')]
        private readonly array $payload,
        #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $receivedAt,
    ) {
    }

    /**
     * The order the events were received in: what a reader keeps to know where it stopped.
     */
    public function getSequence(): int
    {
        return (int) $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
