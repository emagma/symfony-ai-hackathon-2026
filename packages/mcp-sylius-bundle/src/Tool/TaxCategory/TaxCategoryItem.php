<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\TaxCategory;

/**
 * @internal
 */
final class TaxCategoryItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation.'],
            'description' => ['type' => ['string', 'null'], 'description' => 'Free text; null clears it.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
