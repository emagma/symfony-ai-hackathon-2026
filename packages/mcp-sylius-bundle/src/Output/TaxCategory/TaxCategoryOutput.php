<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\TaxCategory;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class TaxCategoryOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $description,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackCode): self
    {
        return new self(
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::string($payload['name'] ?? null),
            description: AdminPayload::string($payload['description'] ?? null),
        );
    }
}
