<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductOption;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ProductOptionOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?int $position,
        /** @var list<ProductOptionValueOutput> */
        public array $values,
        public int $valuesCount,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed>          $payload raw decoded JSON-LD
     * @param list<ProductOptionValueOutput> $values  resolved by the caller: the option payload only carries IRIs
     */
    public static function fromPayload(array $payload, string $locale, string $fallbackCode, array $values = []): self
    {
        $translations = \is_array($payload['translations'] ?? null) ? $payload['translations'] : [];
        $valueIris = AdminPayload::strings($payload['values'] ?? []);

        return new self(
            found: true,
            code: AdminPayload::text($payload['code'] ?? null, $fallbackCode),
            name: AdminPayload::translation($translations, $locale, 'name'),
            position: AdminPayload::int($payload['position'] ?? null),
            values: $values,
            valuesCount: \count($valueIris),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
            updatedAt: AdminPayload::string($payload['updatedAt'] ?? null),
        );
    }
}
