<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ExchangeRate;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ExchangeRateOutput
{
    public function __construct(
        public bool $found,
        public int $id,
        public ?float $ratio,
        public ?string $sourceCurrencyCode,
        public ?string $targetCurrencyCode,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, int $fallbackId): self
    {
        $ratio = $payload['ratio'] ?? null;

        return new self(
            found: true,
            id: \is_int($payload['id'] ?? null) ? $payload['id'] : $fallbackId,
            ratio: \is_int($ratio) || \is_float($ratio) ? (float) $ratio : null,
            sourceCurrencyCode: AdminPayload::code($payload['sourceCurrency'] ?? null),
            targetCurrencyCode: AdminPayload::code($payload['targetCurrency'] ?? null),
        );
    }
}
