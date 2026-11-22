<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductTaxon;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ProductTaxonOutput
{
    public function __construct(
        public ?string $id,
        public ?string $productCode,
        public ?string $taxonCode,
        public ?int $position,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        $id = $payload['id'] ?? null;

        return new self(
            id: \is_int($id) || (\is_string($id) && '' !== $id)
                ? (string) $id
                : AdminPayload::code($payload['@id'] ?? null),
            productCode: AdminPayload::code($payload['product'] ?? null),
            taxonCode: AdminPayload::code($payload['taxon'] ?? null),
            position: AdminPayload::int($payload['position'] ?? null),
        );
    }
}
