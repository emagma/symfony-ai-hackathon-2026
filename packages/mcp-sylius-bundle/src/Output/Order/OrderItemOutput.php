<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Order;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class OrderItemOutput
{
    public function __construct(
        public ?string $id,
        public ?string $productCode,
        public ?string $productName,
        public ?string $variantCode,
        public ?string $variantName,
        public ?int $quantity,
        public ?int $unitPrice,
        public ?int $discountedUnitPrice,
        public ?int $subtotal,
        public ?int $total,
        public ?int $adjustmentsTotal,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        $id = $payload['id'] ?? null;

        return new self(
            id: \is_int($id) || \is_string($id) ? (string) $id : AdminPayload::code($payload['@id'] ?? null),
            productCode: AdminPayload::code($payload['product'] ?? null),
            productName: AdminPayload::string($payload['productName'] ?? null),
            variantCode: AdminPayload::code($payload['variant'] ?? null),
            variantName: AdminPayload::string($payload['variantName'] ?? null),
            quantity: AdminPayload::int($payload['quantity'] ?? null),
            unitPrice: AdminPayload::int($payload['unitPrice'] ?? null),
            discountedUnitPrice: AdminPayload::int($payload['discountedUnitPrice'] ?? null),
            subtotal: AdminPayload::int($payload['subtotal'] ?? null),
            total: AdminPayload::int($payload['total'] ?? null),
            adjustmentsTotal: AdminPayload::int($payload['adjustmentsTotal'] ?? null),
        );
    }
}
