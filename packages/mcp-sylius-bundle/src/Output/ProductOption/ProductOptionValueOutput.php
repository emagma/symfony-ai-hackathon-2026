<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductOption;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * @internal
 */
final readonly class ProductOptionValueOutput
{
    public function __construct(
        public string $code,
        public ?string $value,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $locale): self
    {
        return new self(
            code: AdminPayload::text($payload['code'] ?? null),
            value: AdminPayload::translation($payload['translations'] ?? [], $locale, 'value')
                ?? AdminPayload::string($payload['value'] ?? null),
        );
    }
}
