<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductVariant;

/**
 * @internal
 */
final class ProductVariantItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'productCode' => ['type' => 'string', 'description' => 'Code of the product the variant belongs to. Required on a creation and immutable afterwards: an update naming another product fails (product_immutable).'],
            'locale' => ['type' => 'string', 'description' => 'The locale name targets; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Variant name in `locale`. Needed, with taxCategoryCode, for a shop-ready variant: without both the add-to-cart form rejects it.'],
            'optionValues' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
                'description' => '{optionCode: valueCode} map. Sylius wants a value for every option of the product. On an update REPLACES the whole set (an empty map is ignored).',
            ],
            'pricing' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'integer'],
                'description' => '{channelCode: price} map in minor units of the channel currency. Sylius wants a price for every channel of the product. On an update it is MERGED: channels you omit keep their price (the one collection that is not replaced whole; an empty map is ignored).',
            ],
            'originalPricing' => [
                'type' => 'object',
                'additionalProperties' => ['type' => ['integer', 'null']],
                'description' => '{channelCode: originalPrice} map in minor units: the struck-through "was" price shown next to the price. null removes it. MERGED like pricing; a channel you omit keeps its original price.',
            ],
            'enabled' => ['type' => 'boolean', 'description' => 'true on a creation unless given.'],
            'tracked' => ['type' => 'boolean', 'description' => 'Whether stock is tracked; false on a creation unless given.'],
            'onHand' => ['type' => 'integer', 'description' => 'Stock on hand, meaningful when tracked.'],
            'weight' => ['type' => 'number'],
            'shippingRequired' => ['type' => 'boolean'],
            'taxCategoryCode' => ['type' => 'string', 'description' => 'Code of the tax category the variant is taxed under; must exist.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
