<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Channel;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ChannelOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public bool $enabled,
        public ?string $hostname,
        public ?string $color,
        public ?string $contactEmail,
        public ?string $baseCurrencyCode,
        public ?string $defaultLocaleCode,
        public ?string $defaultTaxZoneCode,
        public ?string $menuTaxonCode,
        public ?string $taxCalculationStrategy,
        /** @var list<string> */
        public array $currencyCodes,
        /** @var list<string> */
        public array $localeCodes,
        /** @var list<string> */
        public array $countryCodes,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackCode): self
    {
        return new self(
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::string($payload['name'] ?? null),
            enabled: (bool) ($payload['enabled'] ?? false),
            hostname: AdminPayload::string($payload['hostname'] ?? null),
            color: AdminPayload::string($payload['color'] ?? null),
            contactEmail: AdminPayload::string($payload['contactEmail'] ?? null),
            baseCurrencyCode: AdminPayload::code($payload['baseCurrency'] ?? null),
            defaultLocaleCode: AdminPayload::code($payload['defaultLocale'] ?? null),
            defaultTaxZoneCode: AdminPayload::code($payload['defaultTaxZone'] ?? null),
            menuTaxonCode: AdminPayload::code($payload['menuTaxon'] ?? null),
            taxCalculationStrategy: AdminPayload::string($payload['taxCalculationStrategy'] ?? null),
            currencyCodes: AdminPayload::codes($payload['currencies'] ?? []),
            localeCodes: AdminPayload::codes($payload['locales'] ?? []),
            countryCodes: AdminPayload::codes($payload['countries'] ?? []),
        );
    }
}
