<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\CatalogPromotion;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Lists the catalog promotions, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_catalog_promotions',
    description: 'Search CATALOG promotions - discounts applied to a product PRICE, shown on the'
        . ' storefront before checkout. For discounts on an order total, use search_promotions instead.'
        . ' Returns code, name, enabled, exclusive, state, priority, startDate, endDate and channels.'
        . ' Scopes and actions are NOT in the listing - get_catalog_promotions returns them. Filters'
        . ' (server-side, from the admin API): name (PARTIAL), code (PARTIAL), state (EXACT - one of'
        . ' inactive, processing, active), enabled (true/false), startDateAfter / startDateBefore,'
        . ' endDateAfter / endDateBefore (ISO-8601). Sorting: sort (code, name, startDate, endDate or'
        . ' priority) with direction (asc/desc, default desc). Detail: get_catalog_promotions.',
)]
#[Permission(resource: 'catalog_promotion', action: Action::Read)]
final readonly class SearchCatalogPromotions
{
    use LeanLocaleHeaderTrait;

    /** @var list<string> mirrors sylius_api.order_filter.admin.catalog_promotion */
    private const SORTABLE = ['code', 'name', 'startDate', 'endDate', 'priority'];

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, hasMore: bool}
     */
    public function __invoke(
        ?string $name = null,
        ?string $code = null,
        #[Schema(enum: ['inactive', 'processing', 'active', null])]
        ?string $state = null,
        ?bool $enabled = null,
        ?string $startDateAfter = null,
        ?string $startDateBefore = null,
        ?string $endDateAfter = null,
        ?string $endDateBefore = null,
        ?string $sort = null,
        ?string $direction = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = ['page' => $pager->page, 'itemsPerPage' => $pager->itemsPerPage];

        foreach (['name' => $name, 'code' => $code, 'state' => $state] as $key => $value) {
            if (null !== $value && '' !== $value) {
                $query[$key] = $value;
            }
        }

        // `false` is a meaningful filter: it must not be dropped as an empty value.
        if (null !== $enabled) {
            $query['enabled'] = $enabled ? 'true' : 'false';
        }

        $bounds = [
            'startDate' => ['after' => $startDateAfter, 'before' => $startDateBefore],
            'endDate' => ['after' => $endDateAfter, 'before' => $endDateBefore],
        ];
        foreach ($bounds as $field => $pair) {
            $range = array_filter($pair, static fn (mixed $v): bool => null !== $v && '' !== $v);
            if ([] !== $range) {
                $query[$field] = $range;
            }
        }

        if (null !== $sort && \in_array($sort, self::SORTABLE, true)) {
            $query['order'] = [$sort => 'asc' === $direction ? 'asc' : 'desc'];
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::CatalogPromotion), $query, self::leanHeaders());

        $members = AdminPayload::members($payload);

        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = [
                'code' => AdminPayload::string($member['code'] ?? null) ?? '',
                'name' => AdminPayload::string($member['name'] ?? null),
                'enabled' => (bool) ($member['enabled'] ?? false),
                'exclusive' => (bool) ($member['exclusive'] ?? false),
                'state' => AdminPayload::string($member['state'] ?? null),
                'priority' => AdminPayload::int($member['priority'] ?? null),
                'startDate' => AdminPayload::string($member['startDate'] ?? null),
                'endDate' => AdminPayload::string($member['endDate'] ?? null),
                'channels' => AdminPayload::codes($member['channels'] ?? null),
            ];
        }

        return $pager->envelope($items, $total);
    }
}
