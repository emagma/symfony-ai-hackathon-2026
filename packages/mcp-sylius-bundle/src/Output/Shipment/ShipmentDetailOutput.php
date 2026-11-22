<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Shipment;

/**
 * Separate from {@see ShipmentOutput}: `found` comes first here, and the key order is the contract.
 *
 * @internal
 */
final readonly class ShipmentDetailOutput
{
    public function __construct(
        public bool $found,
        public ?string $id,
        public ?string $state,
        public ?string $methodCode,
        public ?string $tracking,
        public ?string $shippedAt,
        public ?string $createdAt,
        public ?string $orderTokenValue,
        public int $unitsCount,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        $summary = ShipmentOutput::fromPayload($payload);

        return new self(
            found: true,
            id: $summary->id,
            state: $summary->state,
            methodCode: $summary->methodCode,
            tracking: $summary->tracking,
            shippedAt: $summary->shippedAt,
            createdAt: $summary->createdAt,
            orderTokenValue: $summary->orderTokenValue,
            unitsCount: $summary->unitsCount,
        );
    }
}
