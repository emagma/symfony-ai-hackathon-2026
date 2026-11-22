<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Payment;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * `amount` is in minor units of `currencyCode`, deliberately not divided: the payload
 * does not carry the currency's subunit (JPY has none), so any division would be a guess.
 *
 * @internal
 */
final readonly class PaymentOutput
{
    public function __construct(
        public ?string $id,
        public ?string $state,
        public ?int $amount,
        public ?string $currencyCode,
        public ?string $methodCode,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $orderTokenValue,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            id: AdminPayload::identifier($payload),
            state: AdminPayload::string($payload['state'] ?? null),
            amount: AdminPayload::int($payload['amount'] ?? null),
            currencyCode: AdminPayload::string($payload['currencyCode'] ?? null),
            methodCode: AdminPayload::code($payload['method'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
            updatedAt: AdminPayload::string($payload['updatedAt'] ?? null),
            // The admin Order resource is keyed by tokenValue: the IRI tail is what get_order needs.
            orderTokenValue: AdminPayload::code($payload['order'] ?? null),
        );
    }
}
