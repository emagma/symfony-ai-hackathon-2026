<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductAssociationType;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ProductAssociationTypeOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $locale, string $fallbackCode): self
    {
        $translations = \is_array($payload['translations'] ?? null) ? $payload['translations'] : [];

        return new self(
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::translation($translations, $locale, 'name'),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
            updatedAt: AdminPayload::string($payload['updatedAt'] ?? null),
        );
    }
}
