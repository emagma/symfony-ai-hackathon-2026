<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Order;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Order\OrderSummaryOutput;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Carts never appear: Sylius's StateBasedExtension excludes `state = cart` from every admin order collection.
 * Beyond the filters Sylius declares, the others are the plugin's.
 *
 * @internal
 */
#[McpTool(
    name: 'search_orders',
    description: 'Search admin orders: tokenValue, number, state, paymentState, shippingState, total, currencyCode,'
        . ' checkoutCompletedAt, channelCode, customerId. Carts are excluded. Filters, all server-side:'
        . ' channel, currency, shippingMethod (exact codes); customerId (exact, from search_customers);'
        . ' customerEmail and number (partial, case-insensitive; numbers are zero-padded so "42" matches'
        . ' "000000042"); productName (exact item name); state / paymentState / shippingState (lists, any'
        . ' value matches, values in the schema; e.g. paid but unshipped = paymentState ["paid"] +'
        . ' shippingState ["ready"]; state is the order\'s own: new, fulfilled or cancelled, and every placed'
        . ' order has completed checkout, so "completed" is no order state); completedFrom /'
        . ' completedTo (ISO-8601, inclusive, on checkoutCompletedAt); totalMin/Max (grand total incl. tax)'
        . ' and its two halves itemsTotalMin/Max (goods) and adjustmentsTotalMin/Max (promotions, shipping'
        . ' and tax together, negative when discounts dominate; tax and shipping cannot be filtered apart,'
        . ' Sylius does not store them); itemsCountMin/Max (distinct lines, the "orders with N products"'
        . ' filter); unitsCountMin/Max (total quantity); containsProduct / containsVariant (lists of codes,'
        . ' at least one). Sorting: sort (checkoutCompletedAt, total, number) and direction (default desc).'
        . ' Amounts are in each order\'s currency and currencies share the collection: pair an amount filter'
        . ' with currency or channel. Detail: get_orders by tokenValue, not by number.',
)]
#[Permission(resource: 'order', action: Action::Read)]
final readonly class SearchOrders
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @param list<string>|null $state
     * @param list<string>|null $paymentState
     * @param list<string>|null $shippingState
     * @param list<string>|null $containsProduct
     * @param list<string>|null $containsVariant
     *
     * @return array{
     *     items: list<object>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $channel = null,
        ?string $currency = null,
        ?string $customerId = null,
        ?string $customerEmail = null,
        ?string $number = null,
        ?string $productName = null,
        ?string $shippingMethod = null,
        #[Schema(items: ['type' => 'string', 'enum' => ['new', 'fulfilled', 'cancelled']])]
        ?array $state = null,
        #[Schema(items: ['type' => 'string', 'enum' => ['awaiting_payment', 'partially_authorized', 'authorized', 'partially_paid', 'paid', 'partially_refunded', 'refunded', 'cancelled']])]
        ?array $paymentState = null,
        #[Schema(items: ['type' => 'string', 'enum' => ['ready', 'partially_shipped', 'shipped', 'cancelled']])]
        ?array $shippingState = null,
        ?array $containsProduct = null,
        ?array $containsVariant = null,
        ?string $completedFrom = null,
        ?string $completedTo = null,
        ?int $totalMin = null,
        ?int $totalMax = null,
        ?int $itemsTotalMin = null,
        ?int $itemsTotalMax = null,
        ?int $adjustmentsTotalMin = null,
        ?int $adjustmentsTotalMax = null,
        ?int $itemsCountMin = null,
        ?int $itemsCountMax = null,
        ?int $unitsCountMin = null,
        ?int $unitsCountMax = null,
        ?string $sort = null,
        ?string $direction = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = array_filter([
            'channel.code' => $channel,
            'currencyCode' => $currency,
            'customer.id' => $customerId,
            'items.productName' => $productName,
            'shipments.method.code' => $shippingMethod,
            'customerEmail' => $customerEmail,
            'number' => $number,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        $lists = [
            'state' => $state,
            'paymentState' => $paymentState,
            'shippingState' => $shippingState,
            'containsProduct' => $containsProduct,
            'containsVariant' => $containsVariant,
        ];
        foreach ($lists as $key => $values) {
            $values = self::stringList($values);
            if ([] !== $values) {
                $query[$key] = $values;
            }
        }

        $completedAt = array_filter([
            'after' => $completedFrom,
            'before' => $completedTo,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
        if ([] !== $completedAt) {
            $query['checkoutCompletedAt'] = $completedAt;
        }

        $ranges = [
            'total' => [$totalMin, $totalMax],
            'itemsTotal' => [$itemsTotalMin, $itemsTotalMax],
            'adjustmentsTotal' => [$adjustmentsTotalMin, $adjustmentsTotalMax],
            'itemsCount' => [$itemsCountMin, $itemsCountMax],
            'unitsCount' => [$unitsCountMin, $unitsCountMax],
        ];
        foreach ($ranges as $key => [$min, $max]) {
            // null !== rather than array_filter's default: 0 is a legitimate bound.
            $bounds = array_filter(['gte' => $min, 'lte' => $max], static fn (mixed $v): bool => null !== $v);
            if ([] !== $bounds) {
                $query[$key] = $bounds;
            }
        }

        if (null !== $sort && '' !== $sort) {
            $query['sort'] = array_filter([
                'by' => $sort,
                'direction' => $direction,
            ], static fn (mixed $v): bool => null !== $v && '' !== $v);
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::Order), $query, self::leanHeaders());
        $members = AdminPayload::members($payload);
        $totalItems = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = OrderSummaryOutput::fromPayload($member);
        }

        return $pager->envelope($items, $totalItems);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            if (\is_string($value) && '' !== $value) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }
}
