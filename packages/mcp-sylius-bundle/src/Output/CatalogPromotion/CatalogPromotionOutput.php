<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\CatalogPromotion;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Promotion\ConfiguredElements;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class CatalogPromotionOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $label,
        public ?string $description,
        public bool $enabled,
        public bool $exclusive,
        public ?string $state,
        public ?int $priority,
        public ?string $startDate,
        public ?string $endDate,
        /** @var list<string> */
        public array $channels,
        /** @var list<array{type: string|null, configuration: array<string, mixed>}> */
        public array $scopes,
        /** @var list<array{type: string|null, configuration: array<string, mixed>}> */
        public array $actions,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $locale, string $fallbackCode): self
    {
        $translations = \is_array($payload['translations'] ?? null) ? $payload['translations'] : [];

        return new self(
            found: true,
            code: AdminPayload::string($payload['code'] ?? null) ?? $fallbackCode,
            name: AdminPayload::string($payload['name'] ?? null),
            label: AdminPayload::translation($translations, $locale, 'label'),
            description: AdminPayload::translation($translations, $locale, 'description'),
            enabled: (bool) ($payload['enabled'] ?? false),
            exclusive: (bool) ($payload['exclusive'] ?? false),
            state: AdminPayload::string($payload['state'] ?? null),
            priority: AdminPayload::int($payload['priority'] ?? null),
            startDate: AdminPayload::string($payload['startDate'] ?? null),
            endDate: AdminPayload::string($payload['endDate'] ?? null),
            channels: AdminPayload::codes($payload['channels'] ?? null),
            scopes: ConfiguredElements::fromPayload($payload['scopes'] ?? null),
            actions: ConfiguredElements::fromPayload($payload['actions'] ?? null),
        );
    }
}
