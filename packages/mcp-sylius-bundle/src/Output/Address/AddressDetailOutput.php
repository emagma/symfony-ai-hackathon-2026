<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Address;

use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpCoreBundle\Output\Untrusted;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * The fields are repeated rather than nesting an {@see AddressOutput}: they are flat on the wire.
 *
 * @internal
 */
final readonly class AddressDetailOutput implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
        public bool $found,
        public string $id,
        public ?string $customerId,
        #[Untrusted, Personal]
        public ?string $firstName,
        #[Untrusted, Personal]
        public ?string $lastName,
        #[Untrusted, Personal]
        public ?string $company,
        #[Untrusted, Personal]
        public ?string $street,
        #[Untrusted, Personal]
        public ?string $postcode,
        #[Untrusted, Personal]
        public ?string $city,
        #[Untrusted, Personal]
        public ?string $provinceName,
        public ?string $provinceCode,
        public ?string $countryCode,
        #[Untrusted, Personal, Identifier]
        public ?string $phoneNumber,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload, string $fallbackId): self
    {
        $id = $payload['id'] ?? null;

        return new self(
            found: true,
            id: \is_int($id) || \is_string($id) ? (string) $id : $fallbackId,
            customerId: AdminPayload::code($payload['customer'] ?? null),
            firstName: AdminPayload::string($payload['firstName'] ?? null),
            lastName: AdminPayload::string($payload['lastName'] ?? null),
            company: AdminPayload::string($payload['company'] ?? null),
            street: AdminPayload::string($payload['street'] ?? null),
            postcode: AdminPayload::string($payload['postcode'] ?? null),
            city: AdminPayload::string($payload['city'] ?? null),
            provinceName: AdminPayload::string($payload['provinceName'] ?? null),
            provinceCode: AdminPayload::string($payload['provinceCode'] ?? null),
            countryCode: AdminPayload::string($payload['countryCode'] ?? null),
            phoneNumber: AdminPayload::string($payload['phoneNumber'] ?? null),
        );
    }
}
