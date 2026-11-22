<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Payment;

/**
 * Separate from {@see PaymentOutput}: `found` comes first here, and the key order is the contract.
 *
 * @internal
 */
final readonly class PaymentDetailOutput
{
    public function __construct(
        public bool $found,
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
        $summary = PaymentOutput::fromPayload($payload);

        return new self(
            found: true,
            id: $summary->id,
            state: $summary->state,
            amount: $summary->amount,
            currencyCode: $summary->currencyCode,
            methodCode: $summary->methodCode,
            createdAt: $summary->createdAt,
            updatedAt: $summary->updatedAt,
            orderTokenValue: $summary->orderTokenValue,
        );
    }
}
