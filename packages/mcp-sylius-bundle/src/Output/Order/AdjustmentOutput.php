<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Order;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class AdjustmentOutput
{
    public function __construct(
        public ?string $id,
        public ?string $type,
        public ?string $label,
        public ?int $amount,
        public bool $neutral,
        public bool $locked,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        $id = $payload['id'] ?? null;

        return new self(
            id: \is_int($id) || \is_string($id) ? (string) $id : AdminPayload::code($payload['@id'] ?? null),
            type: AdminPayload::string($payload['type'] ?? null),
            label: AdminPayload::string($payload['label'] ?? null),
            amount: AdminPayload::int($payload['amount'] ?? null),
            neutral: (bool) ($payload['neutral'] ?? false),
            locked: (bool) ($payload['locked'] ?? false),
        );
    }
}
