<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductImage;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ProductImageOutput
{
    public function __construct(
        public bool $found,
        public int $id,
        public string $productCode,
        public ?string $path,
        public ?string $type,
        public ?int $position,
        /** @var list<string> */
        public array $variantCodes,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $productCode, int $fallbackId): self
    {
        $ownerCode = AdminPayload::code($payload['owner'] ?? null);

        return new self(
            found: true,
            id: \is_int($payload['id'] ?? null) ? $payload['id'] : $fallbackId,
            productCode: $ownerCode ?? $productCode,
            path: AdminPayload::string($payload['path'] ?? null),
            type: AdminPayload::string($payload['type'] ?? null),
            position: AdminPayload::int($payload['position'] ?? null),
            variantCodes: AdminPayload::codes($payload['productVariants'] ?? []),
        );
    }
}
