<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\PromotionCoupon;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\PromotionCoupon\PromotionCouponSummaryOutput;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Coupons live under their promotion (`/admin/promotions/{code}/coupons`, no flat collection),
 * hence the `promotion` permissions of the whole domain.
 *
 * @internal
 */
#[McpTool(
    name: 'search_promotion_coupons',
    description: 'Search the coupons of one Sylius admin cart promotion (promotionCode is required - coupons only'
        . ' exist under a promotion). Returns code, usageLimit, used, perCustomerUsageLimit,'
        . ' reusableFromCancelledOrders, expiresAt and createdAt. Filter: code (EXACT - the API has no'
        . ' partial search on coupon codes). Sorting: sort (code, used, expiresAt, usageLimit or'
        . ' perCustomerUsageLimit) with direction (asc/desc, default desc). Default order is most-used'
        . ' first. A generated batch can run to thousands: page through it. When the promotion does not'
        . ' exist returns {found: false, promotionCode: <input>}. Detail: get_promotion_coupons.',
)]
#[Permission(resource: 'promotion', action: Action::Read)]
final readonly class SearchPromotionCoupons
{
    use LeanLocaleHeaderTrait;

    /** @var list<string> mirrors sylius_api.order_filter.admin.promotion_coupon */
    private const SORTABLE = ['code', 'expiresAt', 'usageLimit', 'perCustomerUsageLimit', 'used'];

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $promotionCode,
        ?string $code = null,
        ?string $sort = null,
        ?string $direction = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = ['page' => $pager->page, 'itemsPerPage' => $pager->itemsPerPage];
        if (null !== $code && '' !== $code) {
            $query['code'] = $code;
        }
        if (null !== $sort && \in_array($sort, self::SORTABLE, true)) {
            $query['order'] = [$sort => 'asc' === $direction ? 'asc' : 'desc'];
        }

        $payload = $this->client->getDecoded(
            Route::collection(Resource::PromotionCoupon, ['promotion' => $promotionCode]),
            $query,
            self::leanHeaders(),
        );

        $members = AdminPayload::members($payload);

        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = PromotionCouponSummaryOutput::fromPayload($member, $promotionCode);
        }

        return [
            // The nested collection answers 200 with an empty list for an unknown promotion.
            'found' => [] !== $items || $this->promotionExists($promotionCode),
            'promotionCode' => $promotionCode,
            'items' => $items,
            'total' => $total,
            'page' => $pager->page,
            'hasMore' => ($pager->page * $pager->itemsPerPage) < $total,
        ];
    }

    private function promotionExists(string $promotionCode): bool
    {
        try {
            $this->client->getDecoded(Route::item(Resource::Promotion, $promotionCode));
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return false;
            }

            throw $exception;
        }

        return true;
    }
}
