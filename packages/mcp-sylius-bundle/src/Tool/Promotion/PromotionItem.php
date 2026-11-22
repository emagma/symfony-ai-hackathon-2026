<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Promotion;

use Emagma\McpSyliusBundle\Tool\Shared\PromotionConfigurationTrait;

/**
 * @internal
 */
final class PromotionItem
{
    use PromotionConfigurationTrait;

    private const array CONFIGURED = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => ['type' => ['type' => 'string'], 'configuration' => ['type' => 'object']],
            'required' => ['type'],
        ],
    ];

    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier (letters, digits, underscore); the item is matched on it.'],
            'locale' => ['type' => 'string', 'description' => 'The locale label targets; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation.'],
            'description' => ['type' => 'string'],
            'label' => ['type' => 'string', 'description' => 'Translated label; a creation copies it to every enabled locale.'],
            'priority' => ['type' => 'integer', 'description' => 'Higher runs first.'],
            'exclusive' => ['type' => 'boolean', 'description' => 'Cannot combine with other promotions; false on a creation unless given.'],
            'appliesToDiscounted' => ['type' => 'boolean', 'description' => 'true on a creation unless given.'],
            'couponBased' => ['type' => 'boolean', 'description' => 'Applies only with a coupon (upsert_promotion_coupons afterwards); false on a creation unless given.'],
            'usageLimit' => ['type' => ['integer', 'null']],
            'startsAt' => ['type' => ['string', 'null'], 'description' => 'ISO-8601.'],
            'endsAt' => ['type' => ['string', 'null'], 'description' => 'ISO-8601.'],
            'channels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Channel codes; REPLACES the list when given.'],
            'rules' => self::CONFIGURED + ['description' => 'REPLACED as a whole when given ([] clears). ' . self::RULES_HELP],
            'actions' => self::CONFIGURED + ['description' => 'REPLACED as a whole when given ([] clears); without actions the promotion discounts nothing. ' . self::ACTIONS_HELP],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
