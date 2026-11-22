<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Product;

/**
 * @internal
 */
final class ProductItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'locale' => ['type' => 'string', 'description' => 'The locale the translated fields target; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation, where every translated field given is copied to every enabled locale.'],
            'slug' => ['type' => 'string', 'description' => 'URL slug in `locale`. Required on a creation.'],
            'description' => ['type' => 'string'],
            'shortDescription' => ['type' => 'string'],
            'metaKeywords' => ['type' => 'string'],
            'metaDescription' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean', 'description' => 'true on a creation unless given.'],
            'mainTaxonCode' => ['type' => ['string', 'null'], 'description' => 'Code of the main taxon, which must exist. null or "" detaches it; omit the key to leave it alone. It does not list the product in that category: call update_product_taxons too.'],
            'channelCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Channels the product is sold in; every code must exist. REPLACES the whole collection; omit to leave it alone.'],
            'optionCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Product options (e.g. size, color) the variants are defined on; every code must exist. Nothing is generated: create each variant with upsert_product_variants afterwards. Set them BEFORE the first variant: Sylius freezes them once the product has variants (options_locked_by_variants; delete the variants first). REPLACES the whole collection; omit to leave it alone.'],
            'attributes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Code of an existing product attribute.'],
                        'locale' => ['type' => 'string', 'description' => 'Locale of the value.'],
                        'value' => ['description' => 'Must match the attribute storage type (BOOL/INT/FLOAT/JSON/TEXT) or the shop answers validation_error; a SELECT attribute takes an array of choice keys even for one choice, e.g. ["homme"].'],
                    ],
                    'required' => ['code', 'locale', 'value'],
                    'additionalProperties' => false,
                ],
                'description' => 'REPLACES the entire attribute collection; omit to leave it alone. Secondary taxons (productTaxons) are read-only here: use update_product_taxons.',
            ],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
