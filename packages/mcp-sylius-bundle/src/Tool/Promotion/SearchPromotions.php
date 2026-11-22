<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Promotion;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * Most filters are the plugin's `PromotionCriteriaFilter`: the vendor collection only
 * declares a `coupons.code` search.
 *
 * @internal
 */
#[McpTool(
    name: 'search_promotions',
    description: 'Search CART promotions - discounts applied to an order at checkout. For discounts'
        . ' on a product price, use search_catalog_promotions instead. Returns code, name, priority,'
        . ' exclusive, couponBased, usageLimit, used, startsAt, endsAt, archivedAt and channels. Rules and'
        . ' actions are NOT in the listing - get_promotions returns them. Filters (server-side): name'
        . ' (PARTIAL, case-insensitive), code (PARTIAL), channel (exact code), couponBased (true = requires'
        . ' a coupon), exclusive, activeAt (ISO-8601 date - keeps promotions whose schedule covers it;'
        . ' pass today to get what is running now), couponCode (EXACT - finds the promotion a coupon'
        . ' belongs to), archived (omit for active promotions only - the default; true lists ONLY'
        . ' archived ones). Sorting: sort accepts only "priority", with direction (asc/desc, default desc). Detail: get_promotions.',
)]
#[Permission(resource: 'promotion', action: Action::Read)]
final readonly class SearchPromotions
{
    use LeanLocaleHeaderTrait;

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
        ?string $channel = null,
        ?bool $couponBased = null,
        ?bool $exclusive = null,
        ?string $activeAt = null,
        ?string $couponCode = null,
        ?bool $archived = null,
        ?string $sort = null,
        ?string $direction = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = [
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ];

        foreach (['name' => $name, 'code' => $code, 'channel' => $channel, 'activeAt' => $activeAt] as $key => $value) {
            if (null !== $value && '' !== $value) {
                $query[$key] = $value;
            }
        }

        // No array_filter: `false` has to survive, as the literal word the filter reads back.
        foreach (['couponBased' => $couponBased, 'exclusive' => $exclusive] as $key => $flag) {
            if (null !== $flag) {
                $query[$key] = $flag ? 'true' : 'false';
            }
        }

        if (null !== $couponCode && '' !== $couponCode) {
            $query['coupons.code'] = $couponCode;
        }

        if (null !== $archived) {
            // Sylius hides archived promotions from every collection unless `exists[archivedAt]` is present.
            $query['exists'] = ['archivedAt' => $archived ? 'true' : 'false'];
        }

        if ('priority' === $sort) {
            $query['order'] = ['priority' => 'asc' === $direction ? 'asc' : 'desc'];
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::Promotion), $query, self::leanHeaders());

        $members = AdminPayload::members($payload);

        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = [
                'code' => AdminPayload::string($member['code'] ?? null) ?? '',
                'name' => AdminPayload::string($member['name'] ?? null),
                'priority' => AdminPayload::int($member['priority'] ?? null),
                'exclusive' => (bool) ($member['exclusive'] ?? false),
                'couponBased' => (bool) ($member['couponBased'] ?? false),
                'usageLimit' => AdminPayload::int($member['usageLimit'] ?? null),
                'used' => AdminPayload::int($member['used'] ?? null),
                'startsAt' => AdminPayload::string($member['startsAt'] ?? null),
                'endsAt' => AdminPayload::string($member['endsAt'] ?? null),
                'archivedAt' => AdminPayload::string($member['archivedAt'] ?? null),
                'channels' => AdminPayload::codes($member['channels'] ?? null),
            ];
        }

        return $pager->envelope($items, $total);
    }
}
