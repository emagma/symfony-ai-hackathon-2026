<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Customer;

use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpCoreBundle\Output\Surplus;
use Emagma\McpCoreBundle\Output\Untrusted;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Address\AddressOutput;

/**
 * Property order is the JSON key order: this class is the contract.
 *
 * @internal
 */
final readonly class CustomerOutput implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
        public bool $found,
        public string $id,
        #[Untrusted, Personal, Identifier]
        public ?string $email,
        #[Untrusted, Personal]
        public ?string $firstName,
        #[Untrusted, Personal]
        public ?string $lastName,
        #[Untrusted, Personal]
        public ?string $fullName,
        #[Personal, Surplus]
        public ?string $gender,
        #[Personal, Surplus]
        public ?string $birthday,
        #[Untrusted, Personal, Identifier]
        public ?string $phoneNumber,
        public ?string $groupCode,
        public bool $subscribedToNewsletter,
        public bool $hasAccount,
        public ?string $createdAt,
        public ?AddressOutput $defaultAddress,
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
            email: AdminPayload::string($payload['email'] ?? null),
            firstName: AdminPayload::string($payload['firstName'] ?? null),
            lastName: AdminPayload::string($payload['lastName'] ?? null),
            fullName: AdminPayload::string($payload['fullName'] ?? null),
            gender: AdminPayload::string($payload['gender'] ?? null),
            birthday: AdminPayload::string($payload['birthday'] ?? null),
            phoneNumber: AdminPayload::string($payload['phoneNumber'] ?? null),
            groupCode: AdminPayload::code($payload['group'] ?? null),
            subscribedToNewsletter: (bool) ($payload['subscribedToNewsletter'] ?? false),
            hasAccount: null !== ($payload['user'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
            defaultAddress: AddressOutput::fromPayload($payload['defaultAddress'] ?? null),
        );
    }
}
