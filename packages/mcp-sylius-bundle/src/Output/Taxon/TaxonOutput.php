<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Taxon;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class TaxonOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $slug,
        public ?string $description,
        public bool $enabled,
        public ?int $position,
        public ?int $level,
        public ?string $parentCode,
        /** @var list<string> */
        public array $childrenCodes,
        public int $childrenCount,
        public int $imagesCount,
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
            code: AdminPayload::text($payload['code'] ?? null, $fallbackCode),
            name: AdminPayload::translation($translations, $locale, 'name'),
            slug: AdminPayload::translation($translations, $locale, 'slug'),
            description: AdminPayload::translation($translations, $locale, 'description'),
            enabled: (bool) ($payload['enabled'] ?? false),
            position: AdminPayload::int($payload['position'] ?? null),
            level: AdminPayload::int($payload['level'] ?? null),
            parentCode: AdminPayload::code($payload['parent'] ?? null),
            childrenCodes: AdminPayload::codes($payload['children'] ?? []),
            childrenCount: \count(AdminPayload::strings($payload['children'] ?? [])),
            imagesCount: \is_array($payload['images'] ?? null) ? \count($payload['images']) : 0,
        );
    }
}
