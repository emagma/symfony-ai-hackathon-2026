<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\TaxonImage;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class TaxonImageOutput
{
    public function __construct(
        public bool $found,
        public int $id,
        public string $taxonCode,
        public ?string $path,
        public ?string $type,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $taxonCode, int $fallbackId): self
    {
        $ownerCode = AdminPayload::code($payload['owner'] ?? null);

        return new self(
            found: true,
            id: \is_int($payload['id'] ?? null) ? $payload['id'] : $fallbackId,
            taxonCode: $ownerCode ?? $taxonCode,
            path: AdminPayload::string($payload['path'] ?? null),
            type: AdminPayload::string($payload['type'] ?? null),
        );
    }
}
