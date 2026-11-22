<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAttribute;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * The product PUT replaces `attributes` wholesale: entries are round-tripped exactly as the GET returned them.
 *
 * @internal
 */
trait ProductAttributeValuesPayloadTrait
{
    /**
     * @param array<string, mixed> $product
     *
     * @return array<int, array<string, mixed>>
     */
    private static function attributeEntries(array $product): array
    {
        $items = \is_array($product['attributes'] ?? null) ? $product['attributes'] : [];

        $entries = [];
        foreach (array_values($items) as $index => $item) {
            if (\is_array($item)) {
                $entries[$index] = $item;
            }
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private static function findAttributeEntry(array $entries, string $attributeCode, string $locale): ?int
    {
        foreach ($entries as $index => $entry) {
            if (AdminPayload::code($entry['attribute'] ?? null) !== $attributeCode) {
                continue;
            }
            if (($entry['localeCode'] ?? null) !== $locale) {
                continue;
            }

            return $index;
        }

        return null;
    }

    private static function diffKey(string $attributeCode, string $locale): string
    {
        return 'attribute.' . $attributeCode . '.' . $locale;
    }
}
