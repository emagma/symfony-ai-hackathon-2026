<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle\Screen;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpScreensBundle\Exception\ScreenException;
use Emagma\McpScreensBundle\ScreenInterface;
use Emagma\McpScreensBundle\ScreenResult;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Tool\Address\UpdateAddress;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * One address: the shipping or billing address of an order, or the default address of a customer.
 *
 * It shows a person's address in clear, whatever the client: opening it needs the `pii:reveal` grant on
 * top of the read of addresses. Saved with update_address, confirmed and journaled as such.
 *
 * @internal
 */
final readonly class AddressScreen implements ScreenInterface
{
    public const array FIELDS = [
        'firstName' => 'First name',
        'lastName' => 'Last name',
        'company' => 'Company',
        'street' => 'Street',
        'postcode' => 'Postcode',
        'city' => 'City',
        'provinceName' => 'Province',
        'countryCode' => 'Country code',
        'phoneNumber' => 'Phone',
    ];

    private const array REQUIRED = ['firstName', 'lastName', 'street', 'postcode', 'city', 'countryCode'];

    public function __construct(
        private AdminApiClientInterface $client,
        private UpdateAddress $update,
    ) {
    }

    public function name(): string
    {
        return 'address';
    }

    public function description(): string
    {
        return 'One address, editable. params: {orderToken, kind: "shipping"|"billing"} for the address of an'
            . ' order (shipping by default), {customerId} for the default address of a customer, or {addressId}.'
            . ' The address of an order and the one of a customer are separate copies.';
    }

    public function readPermissions(): array
    {
        return [
            new Permission('address', Action::Read, 'sylius'),
            new Permission('order', Action::Read, 'sylius'),
            new Permission('customer', Action::Read, 'sylius'),
            new Permission('pii', Action::Reveal, 'core'),
        ];
    }

    public function writePermissions(): array
    {
        return [new Permission('address', Action::Write, 'sylius')];
    }

    public function template(): string
    {
        return '@EmagmaMcpSyliusScreens/address.html.twig';
    }

    public function load(array $params): array
    {
        return $this->read($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{subject: string, addressId: string|null, locked: string|null, fields: array<string, array{label: string, value: string, required: bool}>}
     */
    private function read(array $params): array
    {
        [$subject, $address, $locked] = $this->find($params);

        $fields = [];
        foreach (self::FIELDS as $field => $label) {
            $value = $address[$field] ?? null;
            $fields[$field] = [
                'label' => $label,
                'value' => \is_string($value) ? $value : '',
                'required' => \in_array($field, self::REQUIRED, true),
            ];
        }
        $id = $address['id'] ?? null;

        return ['subject' => $subject, 'addressId' => \is_int($id) || \is_string($id) ? (string) $id : self::idOf($address), 'locked' => $locked, 'fields' => $fields];
    }

    public function submit(array $params, array $values, ?RequestContext $context): ScreenResult
    {
        try {
            $data = $this->read($params);
        } catch (ScreenException $exception) {
            return ScreenResult::notSaved($exception->getMessage());
        }
        if (null !== $data['locked']) {
            return ScreenResult::notSaved($data['locked']);
        }
        if (null === $data['addressId']) {
            return ScreenResult::notSaved('This address has no identifier: it cannot be saved.');
        }

        $errors = [];
        $changes = [];
        foreach ($data['fields'] as $field => $current) {
            $typed = $values[$field] ?? null;
            if (!\is_string($typed)) {
                continue;
            }
            $typed = trim($typed);
            if ('' === $typed && $current['required']) {
                $errors[$field] = 'Required.';
            } elseif ('countryCode' === $field && 1 !== preg_match('/^[A-Z]{2}$/', $typed)) {
                $errors[$field] = 'Two capital letters, e.g. FR.';
            } elseif ($typed !== $current['value']) {
                $changes[$field] = $typed;
            }
        }
        if ([] !== $errors) {
            return ScreenResult::invalid($errors);
        }
        if ([] === $changes) {
            return ScreenResult::notSaved('Nothing changed: nothing was saved.');
        }

        $result = ($this->update)($data['addressId'], ...$changes, confirm: false, context: $context);
        if (true === ($result['updated'] ?? false)) {
            return ScreenResult::saved('Address saved.');
        }

        $violations = [];
        foreach (\is_array($result['errors'] ?? null) ? $result['errors'] : [] as $violation) {
            if (\is_array($violation) && \is_string($violation['property'] ?? null) && \is_string($violation['message'] ?? null)) {
                $violations[$violation['property']] = $violation['message'];
            }
        }
        if ([] !== $violations) {
            return ScreenResult::invalid($violations, 'The shop refused these values.');
        }

        return ScreenResult::notSaved(match ($result['reason'] ?? null) {
            'declined', 'cancelled' => 'You did not confirm: nothing was saved.',
            'elicitation_unavailable_preview_only', 'human_confirmation_required' => 'This client cannot ask you to confirm a write: nothing was saved.',
            default => 'Nothing was saved (' . (\is_string($result['reason'] ?? null) ? $result['reason'] : 'refused') . ').',
        });
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{string, array<string, mixed>, string|null} what the address is of, the address, and why it cannot be changed
     */
    private function find(array $params): array
    {
        $string = static function (string $key) use ($params): ?string {
            $value = $params[$key] ?? null;

            return \is_string($value) || \is_int($value) ? ('' === trim((string) $value) ? null : trim((string) $value)) : null;
        };

        try {
            if (null !== $token = $string('orderToken')) {
                $kind = 'billing' === $string('kind') ? 'billing' : 'shipping';
                $order = $this->client->getDecoded(Route::item(Resource::Order, $token));
                $number = \is_string($order['number'] ?? null) ? $order['number'] : $token;

                // A parcel on its way goes where it was sent: the carrier redirects it, not the shop.
                $locked = 'shipping' === $kind && 'shipped' === ($order['shippingState'] ?? null)
                    ? 'This order is already shipped: its shipping address can no longer be changed.'
                    : null;

                return [\sprintf('%s address of order #%s', ucfirst($kind), $number), $this->embedded($order[$kind . 'Address'] ?? null, 'This order has no ' . $kind . ' address.'), $locked];
            }
            if (null !== $customerId = $string('customerId')) {
                $customer = $this->client->getDecoded(Route::item(Resource::Customer, $customerId));

                return ['Default address of customer #' . $customerId, $this->embedded($customer['defaultAddress'] ?? null, 'This customer has no default address.'), null];
            }
            if (null !== $addressId = $string('addressId')) {
                return ['Address #' . $addressId, $this->client->getDecoded(Route::item(Resource::Address, $addressId)), null];
            }
        } catch (ClientException $exception) {
            throw new ScreenException(404 === $exception->getCode() ? 'Not found in the shop.' : 'The shop refused to show this address.', 0, $exception);
        }

        throw new ScreenException('This screen needs params.orderToken (and kind), params.customerId or params.addressId.');
    }

    /**
     * An order or a customer carries its address whole, or as a reference to read.
     *
     * @return array<string, mixed>
     */
    private function embedded(mixed $address, string $missing): array
    {
        if (\is_string($address) && '' !== $address) {
            $id = basename($address);

            return $this->client->getDecoded(Route::item(Resource::Address, $id)) + ['id' => $id];
        }
        if (!\is_array($address)) {
            throw new ScreenException($missing);
        }
        $fields = [];
        foreach ($address as $field => $value) {
            $fields[(string) $field] = $value;
        }
        if (!isset($fields['street']) && null !== $id = self::idOf($fields)) {
            return $this->client->getDecoded(Route::item(Resource::Address, $id)) + ['id' => $id];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $address
     */
    private static function idOf(array $address): ?string
    {
        $id = $address['id'] ?? null;
        if (\is_int($id) || \is_string($id)) {
            return (string) $id;
        }
        $iri = $address['@id'] ?? null;

        return \is_string($iri) && '' !== $iri ? basename($iri) : null;
    }
}
