<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Address;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Address\AddressOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The Admin API replaces an address whole (PUT): the current one is read first and only the given fields change.
 */
#[McpTool(
    name: 'update_address',
    description: 'Update one Sylius address by numeric id: firstName, lastName, company, street, postcode, city,'
        . ' countryCode, provinceCode, provinceName, phoneNumber. Only the fields given change. The id comes'
        . ' from get_orders (shippingAddress / billingAddress of an order) or get_customers (defaultAddress): the'
        . ' address of an order and the one in the customer\'s address book are separate copies, and changing'
        . ' one never changes the other. Correcting the address of an order does NOT recompute its taxes or'
        . ' shipping costs: for another country, the order is to be placed again. An address cannot be created'
        . ' or deleted here. Returns {updated: true, id, diff, before, after} on success.',
)]
#[Permission(resource: 'address', action: Action::Write)]
final readonly class UpdateAddress
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'address';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $id,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $company = null,
        ?string $street = null,
        ?string $postcode = null,
        ?string $city = null,
        ?string $countryCode = null,
        ?string $provinceCode = null,
        ?string $provinceName = null,
        ?string $phoneNumber = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $requested = array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'company' => $company,
            'street' => $street,
            'postcode' => $postcode,
            'city' => $city,
            'countryCode' => $countryCode,
            'provinceCode' => $provinceCode,
            'provinceName' => $provinceName,
            'phoneNumber' => $phoneNumber,
        ], static fn (?string $value): bool => null !== $value);
        if ([] === $requested) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change_requested', 'id' => $id];
        }

        $path = Route::item(Resource::Address, $id);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'id' => $id]);
        }

        $diff = [];
        foreach ($requested as $field => $value) {
            $before = \is_string($current[$field] ?? null) ? $current[$field] : null;
            if ($before !== $value) {
                $diff[$field] = ['before' => $before, 'after' => $value];
            }
        }
        if ([] === $diff) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change', 'id' => $id];
        }

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm the update of address #' . $id,
            'Apply this address update?',
            DiffPreview::targets('Update address #' . $id, $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'updated' => false, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        $body = $current;
        foreach ($diff as $field => $change) {
            $body[$field] = $change['after'];
        }

        try {
            $updated = $this->client->put($path, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'update_address',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'updated' => true,
            'id' => $id,
            'diff' => $diff,
            'before' => AddressOutput::fromPayload($current),
            'after' => AddressOutput::fromPayload($updated),
        ];
    }
}
