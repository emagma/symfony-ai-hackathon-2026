<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Address;

use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpCoreBundle\Output\Untrusted;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * @internal
 */
final readonly class AddressOutput implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
        /** What update_address takes: the address of an order and the one of a customer are distinct rows. */
        public ?string $id,
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
     * Null when the payload carries no address at all, which is not the same as an empty one.
     */
    public static function fromPayload(mixed $payload): ?self
    {
        if (!\is_array($payload)) {
            return null;
        }

        $id = $payload['id'] ?? null;
        if (!\is_int($id) && !\is_string($id)) {
            $id = AdminPayload::code($payload['@id'] ?? null);
        }

        return new self(
            id: null === $id ? null : (string) $id,
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
