<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductAttribute;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ProductAttributeOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public string $type,
        public ?string $storageType,
        public bool $translatable,
        public ?int $position,
        /** @var array<string, mixed>|null */
        public ?array $choices,
        public int $choicesCount,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $locale, string $fallbackCode): self
    {
        $translations = \is_array($payload['translations'] ?? null) ? $payload['translations'] : [];
        $type = AdminPayload::text($payload['type'] ?? null);
        $configuration = \is_array($payload['configuration'] ?? null) ? $payload['configuration'] : [];

        $choices = null;
        $choicesCount = 0;
        if ('select' === $type && isset($configuration['choices']) && \is_array($configuration['choices'])) {
            $choices = $configuration['choices'];
            $choicesCount = \count($choices);
        }

        return new self(
            found: true,
            code: AdminPayload::text($payload['code'] ?? null, $fallbackCode),
            name: AdminPayload::translation($translations, $locale, 'name'),
            type: $type,
            storageType: AdminPayload::string($payload['storageType'] ?? null),
            translatable: (bool) ($payload['translatable'] ?? false),
            position: AdminPayload::int($payload['position'] ?? null),
            choices: $choices,
            choicesCount: $choicesCount,
        );
    }
}
