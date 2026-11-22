<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\PromotionCoupon;

/**
 * @internal
 */
final class PromotionCouponItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'The coupon code customers type (WELCOME10); the item is matched on it under the promotion.'],
            'usageLimit' => ['type' => ['integer', 'null'], 'description' => 'Total redemptions allowed; null for unlimited.'],
            'perCustomerUsageLimit' => ['type' => ['integer', 'null'], 'description' => 'Redemptions allowed per customer; null for unlimited.'],
            'reusableFromCancelledOrders' => ['type' => 'boolean', 'description' => 'A cancelled order gives the coupon back; true on a creation unless given.'],
            'expiresAt' => ['type' => ['string', 'null'], 'description' => 'ISO-8601 date-time; null for no expiry.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
