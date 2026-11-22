<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\CustomerGroup;

/**
 * @internal
 */
final class CustomerGroupItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation. What search_customers(group=) matches on.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
