<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Locale;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class LocaleOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackCode): self
    {
        return new self(
            found: true,
            code: AdminPayload::text($payload['code'] ?? null, $fallbackCode),
            name: AdminPayload::string($payload['name'] ?? null),
        );
    }
}
