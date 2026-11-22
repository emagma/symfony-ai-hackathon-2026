<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ExchangeRate;

/**
 * @internal
 */
final class ExchangeRateItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'sourceCurrencyCode' => ['type' => 'string', 'description' => 'ISO code, e.g. "EUR". The pair (source, target) is the key the item is matched on; Sylius keeps one rate per pair, in either direction.'],
            'targetCurrencyCode' => ['type' => 'string', 'description' => 'ISO code, e.g. "GBP".'],
            'ratio' => ['type' => 'number', 'description' => 'How many target units one source unit buys. Required on a creation; the only editable field afterwards.'],
        ],
        'required' => ['sourceCurrencyCode', 'targetCurrencyCode'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
