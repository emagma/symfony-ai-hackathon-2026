<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Order;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Address\AddressOutput;

/**
 * Every monetary value is in minor units of `currencyCode`.
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class OrderOutput
{
    public function __construct(
        public bool $found,
        public string $tokenValue,
        public ?string $number,
        public ?string $state,
        public ?string $checkoutState,
        public ?string $paymentState,
        public ?string $shippingState,
        public ?string $checkoutCompletedAt,
        public ?string $currencyCode,
        public ?string $localeCode,
        public ?string $channelCode,
        public ?string $customerId,
        public ?int $total,
        public ?int $itemsTotal,
        public ?int $itemsSubtotal,
        public ?int $taxTotal,
        public ?int $shippingTotal,
        public ?int $orderPromotionTotal,
        public ?int $shippingPromotionTotal,
        public int $itemsCount,
        public int $paymentsCount,
        public int $shipmentsCount,
        public ?AddressOutput $shippingAddress,
        public ?AddressOutput $billingAddress,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackTokenValue): self
    {
        return new self(
            found: true,
            tokenValue: AdminPayload::text($payload['tokenValue'] ?? null, $fallbackTokenValue),
            number: AdminPayload::string($payload['number'] ?? null),
            state: AdminPayload::string($payload['state'] ?? null),
            checkoutState: AdminPayload::string($payload['checkoutState'] ?? null),
            paymentState: AdminPayload::string($payload['paymentState'] ?? null),
            shippingState: AdminPayload::string($payload['shippingState'] ?? null),
            checkoutCompletedAt: AdminPayload::string($payload['checkoutCompletedAt'] ?? null),
            currencyCode: AdminPayload::string($payload['currencyCode'] ?? null),
            localeCode: AdminPayload::string($payload['localeCode'] ?? null),
            channelCode: AdminPayload::code($payload['channel'] ?? null),
            customerId: AdminPayload::code($payload['customer'] ?? null),
            total: AdminPayload::int($payload['total'] ?? null),
            itemsTotal: AdminPayload::int($payload['itemsTotal'] ?? null),
            itemsSubtotal: AdminPayload::int($payload['itemsSubtotal'] ?? null),
            taxTotal: AdminPayload::int($payload['taxTotal'] ?? null),
            shippingTotal: AdminPayload::int($payload['shippingTotal'] ?? null),
            orderPromotionTotal: AdminPayload::int($payload['orderPromotionTotal'] ?? null),
            shippingPromotionTotal: AdminPayload::int($payload['shippingPromotionTotal'] ?? null),
            itemsCount: AdminPayload::count($payload['items'] ?? null),
            paymentsCount: AdminPayload::count($payload['payments'] ?? null),
            shipmentsCount: AdminPayload::count($payload['shipments'] ?? null),
            shippingAddress: AddressOutput::fromPayload($payload['shippingAddress'] ?? null),
            billingAddress: AddressOutput::fromPayload($payload['billingAddress'] ?? null),
        );
    }
}
