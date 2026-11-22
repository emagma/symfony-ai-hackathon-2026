<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductAssociation;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * @internal
 */
final readonly class ProductAssociationSummaryOutput
{
    /**
     * @param list<string> $associatedProductCodes
     */
    public function __construct(
        public string $id,
        public ?string $ownerCode,
        public ?string $typeCode,
        public array $associatedProductCodes,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackId = ''): self
    {
        return new self(
            id: AdminPayload::identifier($payload) ?? $fallbackId,
            ownerCode: AdminPayload::code($payload['owner'] ?? null),
            typeCode: AdminPayload::code($payload['type'] ?? null),
            associatedProductCodes: AdminPayload::codes($payload['associatedProducts'] ?? []),
        );
    }
}
