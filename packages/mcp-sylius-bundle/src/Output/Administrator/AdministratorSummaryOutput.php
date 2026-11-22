<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Administrator;

use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * @internal
 */
final readonly class AdministratorSummaryOutput implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
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
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            id: AdminPayload::identifier($payload) ?? '',
            username: AdminPayload::string($payload['username'] ?? null),
            email: AdminPayload::string($payload['email'] ?? null),
            firstName: AdminPayload::string($payload['firstName'] ?? null),
            lastName: AdminPayload::string($payload['lastName'] ?? null),
            localeCode: AdminPayload::string($payload['localeCode'] ?? null),
            enabled: (bool) ($payload['enabled'] ?? false),
            lastLogin: AdminPayload::string($payload['lastLogin'] ?? null),
        );
    }
}
