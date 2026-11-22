<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\PaymentMethod;

/**
 * @internal
 */
final class PaymentMethodItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'locale' => ['type' => 'string', 'description' => 'The locale name, description and instructions target; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation, where it is copied to every enabled locale.'],
            'description' => ['type' => 'string'],
            'instructions' => ['type' => 'string', 'description' => 'Shown to the customer after checkout (bank details, ...).'],
            'channelCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Channels the method is offered in. Required on a creation; on an update REPLACES the list.'],
            'factoryName' => ['type' => 'string', 'description' => 'Payum gateway factory, "offline" on a creation unless given. Create-time only: ignored on an update.'],
            'gatewayName' => ['type' => 'string', 'description' => 'Gateway name, the method name on a creation unless given. Create-time only: ignored on an update.'],
            'gatewayConfig' => ['type' => 'object', 'description' => 'Free-form gateway configuration, stored as gatewayConfig.config. On an update REPLACES the current config (an empty map is ignored).'],
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
