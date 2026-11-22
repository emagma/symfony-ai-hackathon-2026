<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Currency;

/**
 * @internal
 */
final class CurrencyItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'ISO 4217 currency code, e.g. "JPY"; the item is matched on it, and it is all a currency has.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
