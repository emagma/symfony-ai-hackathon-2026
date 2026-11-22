<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductAssociation;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ProductAssociationOutput
{
    public function __construct(
        public bool $found,
        public string $id,
        public ?string $ownerCode,
        public ?string $typeCode,
        /** @var list<string> */
        public array $associatedProductCodes,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackId): self
    {
        return new self(
            found: true,
            id: AdminPayload::identifier($payload) ?? $fallbackId,
            ownerCode: AdminPayload::code($payload['owner'] ?? null),
            typeCode: AdminPayload::code($payload['type'] ?? null),
            associatedProductCodes: AdminPayload::codes($payload['associatedProducts'] ?? []),
        );
    }
}
