<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ShippingMethod;

/**
 * @internal
 */
final class ShippingMethodItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'locale' => ['type' => 'string', 'description' => 'The locale name and description target; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation, where it is copied to every enabled locale.'],
            'description' => ['type' => 'string'],
            'zoneCode' => ['type' => 'string', 'description' => 'Code of the shipping zone. Required on a creation.'],
            'calculator' => ['type' => 'string', 'description' => 'Calculator name, e.g. "flat_rate" or "per_unit_rate". Required on a creation.'],
            'channelCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Channels the method is available in. Required on a creation; on an update REPLACES the list.'],
            'configuration' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'object'],
                'description' => 'Calculator configuration per channel, amounts in minor units, e.g. {"FASHION_WEB":{"amount":500}} for flat_rate. Required on a creation; on an update REPLACES the whole map.',
            ],
            'enabled' => ['type' => 'boolean', 'description' => 'true on a creation unless given.'],
            'position' => ['type' => 'integer', 'description' => 'Sortable position: clamped by the shop, siblings renumbered.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
