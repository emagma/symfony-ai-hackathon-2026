<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Administrator;

use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class AdministratorOutput implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
        public bool $found,
        public string $id,
        #[Personal, Identifier]
        public ?string $username,
        #[Personal, Identifier]
        public ?string $email,
        #[Personal]
        public ?string $firstName,
        #[Personal]
        public ?string $lastName,
        public ?string $localeCode,
        public bool $enabled,
        public ?string $lastLogin,
        public ?string $verifiedAt,
        public ?string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackId): self
    {
        return new self(
            found: true,
            id: AdminPayload::identifier($payload) ?? $fallbackId,
            username: AdminPayload::string($payload['username'] ?? null),
            email: AdminPayload::string($payload['email'] ?? null),
            firstName: AdminPayload::string($payload['firstName'] ?? null),
            lastName: AdminPayload::string($payload['lastName'] ?? null),
            localeCode: AdminPayload::string($payload['localeCode'] ?? null),
            enabled: (bool) ($payload['enabled'] ?? false),
            lastLogin: AdminPayload::string($payload['lastLogin'] ?? null),
            verifiedAt: AdminPayload::string($payload['verifiedAt'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
        );
    }
}
