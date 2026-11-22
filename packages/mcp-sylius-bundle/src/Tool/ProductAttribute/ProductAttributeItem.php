<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAttribute;

/**
 * @internal
 */
final class ProductAttributeItem
{
    public const array TYPES = ['text', 'textarea', 'integer', 'float', 'percent', 'checkbox', 'date', 'datetime', 'select'];

    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'locale' => ['type' => 'string', 'description' => 'The locale name and choice labels target; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation, where it is copied to every enabled locale.'],
            'type' => ['type' => 'string', 'enum' => self::TYPES, 'description' => 'Required on a creation and IMMUTABLE afterwards: an update naming another type fails (type_immutable). Pick it carefully.'],
            'translatable' => ['type' => 'boolean', 'description' => 'Whether product values of this attribute are per locale; false on a creation unless given.'],
            'choices' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
                'description' => 'select type only. The DESIRED full set of choices as {key: label} in `locale`. On an existing attribute it REPLACES the set: keys you omit are removed, labels in other locales are kept for the keys you keep. Omit choices to leave them alone (an empty map is ignored too).',
            ],
            'multiple' => ['type' => 'boolean', 'description' => 'select type only: true for a multi-value select (e.g. olfactory notes); false on a creation unless given.'],
            'min' => ['type' => 'integer', 'description' => 'select type only: lower bound on the number of selected values of a multiple select.'],
            'max' => ['type' => 'integer', 'description' => 'select type only: upper bound on the number of selected values of a multiple select.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
