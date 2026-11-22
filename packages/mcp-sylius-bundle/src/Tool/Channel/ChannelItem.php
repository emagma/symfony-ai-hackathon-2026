<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Channel;

/**
 * @internal
 */
final class ChannelItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation.'],
            'hostname' => ['type' => 'string', 'description' => 'Required on a creation.'],
            'baseCurrencyCode' => ['type' => 'string', 'description' => 'Required on a creation and CREATE-ONLY: Sylius never lets it change. Added to currencyCodes when omitted there.'],
            'defaultLocaleCode' => ['type' => 'string', 'description' => 'Required on a creation. Added to localeCodes when omitted there.'],
            'currencyCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Required on a creation; on an update REPLACES the list.'],
            'localeCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Required on a creation; on an update REPLACES the list.'],
            'countryCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Shipping countries; on an update REPLACES the list.'],
            'taxCalculationStrategy' => ['type' => 'string', 'description' => '"order_items_based" on a creation unless given.'],
            'defaultTaxZoneCode' => ['type' => 'string'],
            'menuTaxonCode' => ['type' => 'string', 'description' => 'Root taxon of the shop navigation; a channel without it has no main menu.'],
            'description' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'themeName' => ['type' => 'string'],
            'contactEmail' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean', 'description' => 'true on a creation unless given.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
