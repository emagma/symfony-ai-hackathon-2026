<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Payment;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Payment\PaymentOutput;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * The admin order collection has no payment-state filter of its own: this collection does.
 *
 * @internal
 */
#[McpTool(
    name: 'search_payments',
    description: 'Search Sylius admin payments across all orders (id, state, amount, currencyCode, methodCode,'
        . ' createdAt, updatedAt, orderTokenValue). The gateway `details` blob is never returned. Filters'
        . ' (server-side): state (EXACT, a payment\'s own, values in the schema: completed means paid here),'
        . ' channel (exact channel code of the owning order). Amounts are in minor units of each payment'
        . ' currencyCode. Follow orderTokenValue into get_orders for the order behind a payment. Detail: get_payments (ids).',
)]
#[Permission(resource: 'payment', action: Action::Read)]
final readonly class SearchPayments
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
        #[Schema(enum: ['new', 'processing', 'authorized', 'completed', 'failed', 'cancelled', 'refunded', 'unknown', null])]
        ?string $state = null,
        ?string $channel = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = array_filter([
            'state' => $state,
            'order.channel.code' => $channel,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        $payload = $this->client->getDecoded(Route::collection(Resource::Payment), $query, self::leanHeaders());
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            if (\is_array($member)) {
                $items[] = PaymentOutput::fromPayload($member);
            }
        }

        return $pager->envelope($items, $total);
    }
}
