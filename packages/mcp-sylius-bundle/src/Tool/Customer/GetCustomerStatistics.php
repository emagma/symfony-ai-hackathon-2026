<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Customer;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Reads customer statistics one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_customer_statistics',
    description: 'Get the order statistics of one Sylius admin customer by numeric id: total order count,'
        . ' and per channel the orders count, the cumulated order value and the average order value.'
        . ' Amounts are in MINOR UNITS of each channel currency - that is why they are reported per'
        . ' channel and never summed across them.'
        . ' To find the customers ABOVE a spending threshold rather than inspect one, use'
        . ' search_customers(ordersTotalMin=, channel=).'
        . ' When the id does not exist returns {found: false, id: <input>}.',
)]
#[Permission(resource: 'customer', action: Action::Read)]
final readonly class GetCustomerStatistics
{
    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $id): array
    {
        try {
            $statistics = $this->client->getDecoded(
                Route::action(Resource::Customer, $id, 'statistics'),
            );
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return ['found' => false, 'id' => $id];
            }

            throw $exception;
        }

        // JSON-LD serialises the rows as a Hydra collection, plain JSON as a list: read both.
        $perChannel = [];
        $raw = $statistics['perChannelsStatistics'] ?? null;
        $rows = [];
        if (\is_array($raw) && array_is_list($raw)) {
            $rows = $raw;
        } elseif (\is_array($raw)) {
            /** @var array<string, mixed> $collection */
            $collection = $raw;
            $rows = AdminPayload::members($collection);
        }
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $perChannel[] = [
                'channelCode' => AdminPayload::codeOf($row['channel'] ?? null),
                'ordersCount' => AdminPayload::int($row['ordersCount'] ?? null),
                'ordersValue' => AdminPayload::int($row['ordersValue'] ?? null),
                'averageOrderValue' => AdminPayload::int($row['averageOrderValue'] ?? null),
            ];
        }

        return [
            'found' => true,
            'id' => $id,
            'ordersCount' => AdminPayload::int($statistics['allOrdersCount'] ?? null),
            'perChannel' => $perChannel,
        ];
    }
}
