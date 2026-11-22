<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Order;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Fed by the `mcp:order:lean` group. Every monetary value is in minor units of `currencyCode`.
 *
 * @internal
 */
final readonly class OrderSummaryOutput
{
    public function __construct(
        public string $tokenValue,
        public ?string $number,
        public ?string $state,
        public ?string $paymentState,
        public ?string $shippingState,
        public ?int $total,
        public ?string $currencyCode,
        public ?string $checkoutCompletedAt,
        public ?string $channelCode,
        public ?string $customerId,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            tokenValue: AdminPayload::text($payload['tokenValue'] ?? null),
            number: AdminPayload::string($payload['number'] ?? null),
            state: AdminPayload::string($payload['state'] ?? null),
            paymentState: AdminPayload::string($payload['paymentState'] ?? null),
            shippingState: AdminPayload::string($payload['shippingState'] ?? null),
            total: AdminPayload::int($payload['total'] ?? null),
            currencyCode: AdminPayload::string($payload['currencyCode'] ?? null),
            checkoutCompletedAt: AdminPayload::string($payload['checkoutCompletedAt'] ?? null),
            channelCode: AdminPayload::code($payload['channel'] ?? null),
            customerId: AdminPayload::code($payload['customer'] ?? null),
        );
    }
}
