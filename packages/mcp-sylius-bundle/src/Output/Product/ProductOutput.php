<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Product;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ProductOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $slug,
        public ?string $description,
        public ?string $shortDescription,
        public ?string $metaKeywords,
        public ?string $metaDescription,
        /** @var list<string> the locales that have a translation: a text asked in another one falls back to one of them */
        public array $translatedLocales,
        public bool $enabled,
        public float $averageRating,
        public string $variantSelectionMethod,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $mainTaxonCode,
        /** @var list<string> */
        public array $channelCodes,
        /** @var list<string> */
        public array $optionCodes,
        public int $taxonsCount,
        public int $imagesCount,
        public int $attributesCount,
        public int $variantsCount,
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
            shortDescription: AdminPayload::translation($translations, $locale, 'shortDescription'),
            metaKeywords: AdminPayload::translation($translations, $locale, 'metaKeywords'),
            metaDescription: AdminPayload::translation($translations, $locale, 'metaDescription'),
            translatedLocales: self::translatedLocales($translations),
            enabled: (bool) ($payload['enabled'] ?? false),
            averageRating: AdminPayload::float($payload['averageRating'] ?? null) ?? 0.0,
            variantSelectionMethod: AdminPayload::text($payload['variantSelectionMethod'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
            updatedAt: AdminPayload::string($payload['updatedAt'] ?? null),
            mainTaxonCode: AdminPayload::code($payload['mainTaxon'] ?? null),
            channelCodes: AdminPayload::codes($payload['channels'] ?? []),
            optionCodes: AdminPayload::codes($payload['options'] ?? []),
            taxonsCount: \count(AdminPayload::strings($payload['productTaxons'] ?? [])),
            imagesCount: \is_array($payload['images'] ?? null) ? \count($payload['images']) : 0,
            attributesCount: \is_array($payload['attributes'] ?? null) ? \count($payload['attributes']) : 0,
            variantsCount: \count(AdminPayload::strings($payload['variants'] ?? [])),
        );
    }

    /**
     * @param array<mixed> $translations
     *
     * @return list<string>
     */
    private static function translatedLocales(array $translations): array
    {
        $locales = [];
        foreach ($translations as $locale => $translation) {
            if (\is_string($locale) && \is_array($translation) && \is_string($translation['name'] ?? null) && '' !== trim($translation['name'])) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }
}
