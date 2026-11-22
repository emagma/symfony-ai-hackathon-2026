<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Country;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class CountryOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public bool $enabled,
        public int $provincesCount,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackCode): self
    {
        $provinces = $payload['provinces'] ?? [];

        return new self(
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::string($payload['name'] ?? null),
            enabled: (bool) ($payload['enabled'] ?? false),
            provincesCount: \is_array($provinces) ? \count($provinces) : 0,
        );
    }
}
