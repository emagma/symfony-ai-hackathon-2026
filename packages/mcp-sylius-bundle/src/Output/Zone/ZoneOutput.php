<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Zone;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class ZoneOutput
{
    public function __construct(
        public bool $found,
        public string $code,
        public ?string $name,
        public ?string $type,
        public ?string $scope,
        public ?int $priority,
        /** @var list<string> */
        public array $memberCodes,
        public int $membersCount,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackCode): self
    {
        $memberCodes = [];
        $members = $payload['members'] ?? [];
        if (\is_array($members)) {
            foreach ($members as $m) {
                if (\is_string($m) && '' !== $m) {
                    $memberCodes[] = basename($m);
                } elseif (\is_array($m) && \is_string($m['code'] ?? null) && '' !== $m['code']) {
                    $memberCodes[] = $m['code'];
                }
            }
        }

        return new self(
            found: true,
            code: \is_string($payload['code'] ?? null) ? $payload['code'] : $fallbackCode,
            name: AdminPayload::string($payload['name'] ?? null),
            type: AdminPayload::string($payload['type'] ?? null),
            scope: AdminPayload::string($payload['scope'] ?? null),
            priority: AdminPayload::int($payload['priority'] ?? null),
            memberCodes: $memberCodes,
            membersCount: \count($memberCodes),
        );
    }
}
