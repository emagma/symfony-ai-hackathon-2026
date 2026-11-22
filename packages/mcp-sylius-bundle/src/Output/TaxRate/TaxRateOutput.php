<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\TaxRate;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class TaxRateOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?float $amount,
        public bool $includedInPrice,
        public ?string $zoneCode,
        public ?string $categoryCode,
        public ?string $calculator,
        public ?string $startDate,
        public ?string $endDate,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackCode): self
    {
        $amount = $payload['amount'] ?? null;

        return new self(
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::string($payload['name'] ?? null),
            amount: \is_int($amount) || \is_float($amount) ? (float) $amount : null,
            includedInPrice: (bool) ($payload['includedInPrice'] ?? false),
            zoneCode: AdminPayload::code($payload['zone'] ?? null),
            categoryCode: AdminPayload::code($payload['category'] ?? null),
            calculator: AdminPayload::string($payload['calculator'] ?? null),
            startDate: AdminPayload::string($payload['startDate'] ?? null),
            endDate: AdminPayload::string($payload['endDate'] ?? null),
        );
    }
}
