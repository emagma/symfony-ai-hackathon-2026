<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Zone;

/**
 * @internal
 */
final class ZoneItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation.'],
            'type' => ['type' => 'string', 'enum' => ['zone', 'country', 'province'], 'description' => 'What the members are. Required on a creation, immutable afterwards.'],
            'scope' => ['type' => 'string', 'enum' => ['all', 'shipping', 'tax'], 'description' => 'Where the zone applies; "all" on a creation unless given, immutable afterwards.'],
            'priority' => ['type' => 'integer', 'description' => 'Which zone wins when several match an address (Sylius 2.2 or later).'],
            'members' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Member codes (country codes for type country, …). When given, REPLACES the whole collection.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
