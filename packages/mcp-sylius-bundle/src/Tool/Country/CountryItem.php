<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Country;

/**
 * @internal
 */
final class CountryItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2, e.g. "JP"; the item is matched on it.'],
            'enabled' => ['type' => 'boolean', 'description' => 'Whether the country is offered; a country cannot be deleted, only disabled.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
