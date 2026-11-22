<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\PaymentMethod;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class PaymentMethodOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $description,
        public ?string $instructions,
        public bool $enabled,
        public ?int $position,
        /** @var list<string> */
        public array $channelCodes,
        public ?string $gatewayCode,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $locale, string $fallbackCode): self
    {
        $translations = \is_array($payload['translations'] ?? null) ? $payload['translations'] : [];
        $gatewayConfig = \is_array($payload['gatewayConfig'] ?? null) ? $payload['gatewayConfig'] : [];
        $gatewayCode = AdminPayload::string($gatewayConfig['gatewayName'] ?? $gatewayConfig['gateway'] ?? null);

        return new self(
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::translation($translations, $locale, 'name'),
            description: AdminPayload::translation($translations, $locale, 'description'),
            instructions: AdminPayload::translation($translations, $locale, 'instructions'),
            enabled: (bool) ($payload['enabled'] ?? false),
            position: AdminPayload::int($payload['position'] ?? null),
            channelCodes: AdminPayload::codes($payload['channels'] ?? []),
            gatewayCode: $gatewayCode,
        );
    }
}
