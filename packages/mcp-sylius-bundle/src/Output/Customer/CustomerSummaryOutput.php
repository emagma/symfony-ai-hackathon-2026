<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Customer;

use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpCoreBundle\Output\Untrusted;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * @internal
 */
final readonly class CustomerSummaryOutput implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
        public ?string $id,
        #[Untrusted, Personal, Identifier]
        public ?string $email,
        #[Untrusted, Personal]
        public ?string $fullName,
        public ?string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            id: AdminPayload::identifier($payload),
            email: AdminPayload::string($payload['email'] ?? null),
            fullName: AdminPayload::string($payload['fullName'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
        );
    }
}
