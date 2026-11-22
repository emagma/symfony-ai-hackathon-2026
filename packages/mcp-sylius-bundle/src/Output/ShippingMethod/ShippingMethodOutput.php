<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ShippingMethod;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ShippingMethodOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $description,
        public bool $enabled,
        public ?int $position,
        public ?string $zoneCode,
        /** @var list<string> */
        public array $channelCodes,
        public ?string $calculator,
        /** @var array<string, array<string, mixed>> */
        public array $chargesPerChannel,
        public ?string $taxCategoryCode,
        public ?string $categoryCode,
        public ?int $minDeliveryTimeDays,
        public ?int $maxDeliveryTimeDays,
        public ?string $archivedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $locale, string $fallbackCode): self
    {
        $translations = \is_array($payload['translations'] ?? null) ? $payload['translations'] : [];
        // The Sylius admin serializer renames these via `serialized-name`: both names are read.
        $calculator = AdminPayload::string(
            $payload['shippingChargesCalculator']
            ?? $payload['calculator']
            ?? null,
        );
        $rawConfig = $payload['shippingChargesCalculatorConfiguration']
            ?? $payload['configuration']
            ?? null;
        /** @var array<string, array<string, mixed>> $chargesPerChannel keyed by channel code */
        $chargesPerChannel = [];
        if (\is_array($rawConfig)) {
            foreach ($rawConfig as $channelCode => $value) {
                if (\is_string($channelCode) && \is_array($value)) {
                    $chargesPerChannel[$channelCode] = $value;
                }
            }
        }

        return new self(
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::translation($translations, $locale, 'name'),
            description: AdminPayload::translation($translations, $locale, 'description'),
            enabled: (bool) ($payload['enabled'] ?? false),
            position: AdminPayload::int($payload['position'] ?? null),
            zoneCode: AdminPayload::code($payload['zone'] ?? null),
            channelCodes: AdminPayload::codes($payload['channels'] ?? []),
            calculator: $calculator,
            chargesPerChannel: $chargesPerChannel,
            taxCategoryCode: AdminPayload::code($payload['taxCategory'] ?? null),
            categoryCode: AdminPayload::code($payload['category'] ?? null),
            minDeliveryTimeDays: AdminPayload::int($payload['minDeliveryTimeDays'] ?? null),
            maxDeliveryTimeDays: AdminPayload::int($payload['maxDeliveryTimeDays'] ?? null),
            archivedAt: AdminPayload::string($payload['archivedAt'] ?? null),
        );
    }
}
