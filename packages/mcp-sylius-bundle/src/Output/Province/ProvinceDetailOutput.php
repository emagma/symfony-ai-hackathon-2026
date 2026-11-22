<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Province;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Separate from {@see ProvinceOutput}: `countryCode` comes first here, and the key order is the contract.
 *
 * @internal
 */
final readonly class ProvinceDetailOutput
{
    public function __construct(
        public string $countryCode,
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $abbreviation,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $countryCode, string $fallbackCode): self
    {
        return new self(
            countryCode: $countryCode,
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::string($payload['name'] ?? null),
            abbreviation: AdminPayload::string($payload['abbreviation'] ?? null),
        );
    }
}
