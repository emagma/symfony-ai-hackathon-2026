<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Promotion;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class PromotionOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $label,
        public ?string $description,
        public ?int $priority,
        public bool $exclusive,
        public bool $appliesToDiscounted,
        public bool $couponBased,
        public ?int $usageLimit,
        public ?int $used,
        public ?string $startsAt,
        public ?string $endsAt,
        public ?string $archivedAt,
        /** @var list<string> */
        public array $channels,
        public int $couponCount,
        /** @var list<array{type: string|null, configuration: array<string, mixed>}> */
        public array $rules,
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
            description: AdminPayload::string($payload['description'] ?? null),
            priority: AdminPayload::int($payload['priority'] ?? null),
            exclusive: (bool) ($payload['exclusive'] ?? false),
            appliesToDiscounted: (bool) ($payload['appliesToDiscounted'] ?? false),
            couponBased: (bool) ($payload['couponBased'] ?? false),
            usageLimit: AdminPayload::int($payload['usageLimit'] ?? null),
            used: AdminPayload::int($payload['used'] ?? null),
            startsAt: AdminPayload::string($payload['startsAt'] ?? null),
            endsAt: AdminPayload::string($payload['endsAt'] ?? null),
            archivedAt: AdminPayload::string($payload['archivedAt'] ?? null),
            channels: AdminPayload::codes($payload['channels'] ?? null),
            couponCount: \is_array($payload['coupons'] ?? null) ? \count($payload['coupons']) : 0,
            rules: ConfiguredElements::fromPayload($payload['rules'] ?? null),
            actions: ConfiguredElements::fromPayload($payload['actions'] ?? null),
        );
    }
}
