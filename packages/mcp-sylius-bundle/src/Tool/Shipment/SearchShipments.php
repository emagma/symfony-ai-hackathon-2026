<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shipment;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Shipment\ShipmentSummaryOutput;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Lists the shipments, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_shipments',
    description: 'Search Sylius admin shipments across all orders (id, state, methodCode, tracking, shippedAt,'
        . ' createdAt, orderTokenValue). Filters (server-side): state (EXACT: ready, shipped,'
        . ' cancelled), method (exact shipping method code), channel (exact channel code of the owning'
        . ' order). Follow orderTokenValue into get_orders for the order behind a shipment. Use get_shipments'
        . ' for the shipped unit count. Detail: get_shipments (ids).',
)]
#[Permission(resource: 'shipment', action: Action::Read)]
final readonly class SearchShipments
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{items: list<object>, total: int, page: int, hasMore: bool}
     */
    public function __invoke(
        #[Schema(enum: ['ready', 'shipped', 'cancelled', null])]
        ?string $state = null,
        ?string $method = null,
        ?string $channel = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = array_filter([
            'state' => $state,
            'method.code' => $method,
            'order.channel.code' => $channel,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        $payload = $this->client->getDecoded(Route::collection(Resource::Shipment), $query, self::leanHeaders());
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            if (\is_array($member)) {
                $items[] = ShipmentSummaryOutput::fromPayload($member);
            }
        }

        return $pager->envelope($items, $total);
    }
}
