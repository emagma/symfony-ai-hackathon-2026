<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductOption;

/**
 * @internal
 */
final class ProductOptionItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'locale' => ['type' => 'string', 'description' => 'The locale name and value labels target; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation, where it is copied to every enabled locale.'],
            'position' => ['type' => 'integer', 'description' => 'Honoured on a creation only: the admin PUT ignores it afterwards.'],
            'values' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
                'description' => 'The DESIRED full set of values as {valueCode: label} in `locale`. On an existing option it REPLACES the set: codes you omit are DELETED (cascade), new codes are created, changed labels are updated, labels in other locales are kept. Omit values to leave them alone (an empty map is ignored too: an option cannot be emptied here); add_product_option_value adds one without touching the rest.',
            ],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
