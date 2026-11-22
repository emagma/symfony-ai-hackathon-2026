<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\TaxRate;

/**
 * @internal
 */
final class TaxRateItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation.'],
            'amount' => ['type' => 'number', 'description' => 'Fraction, not percent: 0.2 is 20 %. Required on a creation.'],
            'zoneCode' => ['type' => 'string', 'description' => 'Code of the zone the rate applies in (see search_zones). Required on a creation.'],
            'categoryCode' => ['type' => 'string', 'description' => 'Code of the tax category (see search_tax_categories). Required on a creation.'],
            'includedInPrice' => ['type' => 'boolean', 'description' => 'Whether the displayed price already contains the tax; false on a creation unless given.'],
            'calculator' => ['type' => 'string', 'description' => 'Calculator service name; "default" on a creation unless given.'],
            'startDate' => ['type' => ['string', 'null'], 'description' => 'ISO-8601 date-time from which the rate applies.'],
            'endDate' => ['type' => ['string', 'null'], 'description' => 'ISO-8601 date-time after which it no longer applies.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
