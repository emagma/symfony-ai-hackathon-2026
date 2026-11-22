<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductVariant;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ProductVariantOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $productCode,
        public ?string $name,
        public bool $enabled,
        public ?int $position,
        public bool $tracked,
        public ?int $onHand,
        public ?int $onHold,
        /** @var list<string> */
        public array $optionValueCodes,
        /** @var array<string, int> */
        public array $pricing,
        /** @var array<string, int> */
        public array $originalPricing,
        public ?float $weight,
        public ?float $width,
        public ?float $height,
        public ?float $depth,
        public bool $shippingRequired,
        public ?string $taxCategoryCode,
        public ?string $shippingCategoryCode,
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
            code: AdminPayload::text($payload['code'] ?? null, $fallbackCode),
            productCode: AdminPayload::code($payload['product'] ?? null),
            name: AdminPayload::translation($translations, $locale, 'name'),
            enabled: (bool) ($payload['enabled'] ?? false),
            position: AdminPayload::int($payload['position'] ?? null),
            tracked: (bool) ($payload['tracked'] ?? false),
            onHand: AdminPayload::int($payload['onHand'] ?? null),
            onHold: AdminPayload::int($payload['onHold'] ?? null),
            optionValueCodes: AdminPayload::codes($payload['optionValues'] ?? []),
            pricing: self::pricing($payload['channelPricings'] ?? null, 'price'),
            originalPricing: self::pricing($payload['channelPricings'] ?? null, 'originalPrice'),
            weight: AdminPayload::float($payload['weight'] ?? null),
            width: AdminPayload::float($payload['width'] ?? null),
            height: AdminPayload::float($payload['height'] ?? null),
            depth: AdminPayload::float($payload['depth'] ?? null),
            shippingRequired: (bool) ($payload['shippingRequired'] ?? false),
            taxCategoryCode: AdminPayload::code($payload['taxCategory'] ?? null),
            shippingCategoryCode: AdminPayload::code($payload['shippingCategory'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
            updatedAt: AdminPayload::string($payload['updatedAt'] ?? null),
        );
    }

    /**
     * Channel code => `$field` in minor units. An empty map encodes as `[]`, not `{}`:
     * a wart, but part of the contract.
     *
     * @return array<string, int>
     */
    private static function pricing(mixed $channelPricings, string $field): array
    {
        if (!\is_array($channelPricings)) {
            return [];
        }
        $out = [];
        foreach ($channelPricings as $key => $pricing) {
            if (!\is_array($pricing)) {
                continue;
            }
            $channelCode = \is_string($pricing['channelCode'] ?? null) ? $pricing['channelCode'] : (string) $key;
            $price = $pricing[$field] ?? null;
            if (\is_int($price)) {
                $out[$channelCode] = $price;
            }
        }

        return $out;
    }
}
