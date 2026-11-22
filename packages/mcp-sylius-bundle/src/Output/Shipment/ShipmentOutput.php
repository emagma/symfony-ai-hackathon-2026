<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Shipment;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * The fields are repeated from {@see ShipmentSummaryOutput} rather than nested: the shape is flat on the wire.
 *
 * @internal
 */
final readonly class ShipmentOutput
{
    public function __construct(
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
        $summary = ShipmentSummaryOutput::fromPayload($payload);

        return new self(
            id: $summary->id,
            state: $summary->state,
            methodCode: $summary->methodCode,
            tracking: $summary->tracking,
            shippedAt: $summary->shippedAt,
            createdAt: $summary->createdAt,
            orderTokenValue: $summary->orderTokenValue,
            unitsCount: AdminPayload::count($payload['units'] ?? null),
        );
    }
}
