<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Shipment;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * @internal
 */
final readonly class ShipmentSummaryOutput
{
    public function __construct(
        public ?string $id,
        public ?string $state,
        public ?string $methodCode,
        public ?string $tracking,
        public ?string $shippedAt,
        public ?string $createdAt,
        public ?string $orderTokenValue,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            id: AdminPayload::identifier($payload),
            state: AdminPayload::string($payload['state'] ?? null),
            methodCode: AdminPayload::code($payload['method'] ?? null),
            tracking: AdminPayload::string($payload['tracking'] ?? null),
            shippedAt: AdminPayload::string($payload['shippedAt'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
            // The admin Order resource is keyed by tokenValue: the IRI tail is what get_order needs.
            orderTokenValue: AdminPayload::code($payload['order'] ?? null),
        );
    }
}
