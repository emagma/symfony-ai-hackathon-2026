<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\PromotionCoupon;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class PromotionCouponOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public string $promotionCode,
        public ?int $usageLimit,
        public ?int $used,
        public ?int $perCustomerUsageLimit,
        public bool $reusableFromCancelledOrders,
        public ?string $expiresAt,
        public ?string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $promotionCode, string $fallbackCode): self
    {
        return new self(
            found: true,
            code: AdminPayload::string($payload['code'] ?? null) ?? $fallbackCode,
            promotionCode: AdminPayload::code($payload['promotion'] ?? null) ?? $promotionCode,
            usageLimit: AdminPayload::int($payload['usageLimit'] ?? null),
            used: AdminPayload::int($payload['used'] ?? null),
            perCustomerUsageLimit: AdminPayload::int($payload['perCustomerUsageLimit'] ?? null),
            reusableFromCancelledOrders: (bool) ($payload['reusableFromCancelledOrders'] ?? true),
            expiresAt: AdminPayload::string($payload['expiresAt'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
        );
    }
}
