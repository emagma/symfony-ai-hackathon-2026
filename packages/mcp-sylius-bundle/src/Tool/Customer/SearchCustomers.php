<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Customer;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Customer\CustomerSummaryOutput;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * Lists the customers, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_customers',
    description: 'Search admin customers: id, email, fullName, createdAt (phone, birthday, gender and address come'
        . ' from get_customers only). Filters, server-side: email, firstName, lastName (partial,'
        . ' case-insensitive); group (exact group NAME) or groupCode (exact CODE, stabler; both from'
        . ' search_customer_groups); createdFrom / createdTo (ISO-8601, inclusive, signup date);'
        . ' subscribedToNewsletter; hasAccount (false keeps guests). Order-value filters:'
        . ' ordersTotalMin/Max (cumulated order total, the "spent more than X" filter; REQUIRES channel so'
        . ' that currencies are not added together), ordersCountMin/Max, lastOrderAfter / lastOrderBefore'
        . ' (date of the most recent order; lastOrderBefore finds dormant customers; customers who never'
        . ' ordered are excluded). These four are scoped by channel (exact code) and orderPaymentStates'
        . ' (default: paid only); carts never count. Sorting: sort (createdAt, email, firstName, lastName)'
        . ' and direction (default desc, newest first). Detail: get_customers (ids); order counts and lifetime'
        . ' value per channel: get_customer_statistics.',
)]
#[Permission(resource: 'customer', action: Action::Read)]
final readonly class SearchCustomers
{
    use LeanLocaleHeaderTrait;

    /** @var list<string> mirrors sylius_api.order_filter.admin.customer */
    private const SORTABLE = ['createdAt', 'email', 'firstName', 'lastName'];

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @param list<string>|null $orderPaymentStates
     *
     * @return array{items: list<object>, total: int, page: int, hasMore: bool}
     */
    public function __invoke(
        ?string $email = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $group = null,
        ?string $groupCode = null,
        ?string $createdFrom = null,
        ?string $createdTo = null,
        ?bool $subscribedToNewsletter = null,
        ?bool $hasAccount = null,
        ?string $channel = null,
        ?array $orderPaymentStates = null,
        ?int $ordersTotalMin = null,
        ?int $ordersTotalMax = null,
        ?int $ordersCountMin = null,
        ?int $ordersCountMax = null,
        ?string $lastOrderAfter = null,
        ?string $lastOrderBefore = null,
        ?string $sort = null,
        ?string $direction = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = array_filter([
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'group.name' => $group,
            // A filter of the plugin, not of Sylius.
            'groupCode' => $groupCode,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        foreach (['subscribedToNewsletter' => $subscribedToNewsletter, 'hasAccount' => $hasAccount] as $key => $flag) {
            if (null !== $flag) {
                $query[$key] = $flag ? 'true' : 'false';
            }
        }

        $createdAt = array_filter([
            'after' => $createdFrom,
            'before' => $createdTo,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
        if ([] !== $createdAt) {
            $query['createdAt'] = $createdAt;
        }

        $aggregateRanges = [
            'ordersTotal' => ['gte' => $ordersTotalMin, 'lte' => $ordersTotalMax],
            'ordersCount' => ['gte' => $ordersCountMin, 'lte' => $ordersCountMax],
            'lastOrder' => ['after' => $lastOrderAfter, 'before' => $lastOrderBefore],
        ];
        foreach ($aggregateRanges as $key => $bounds) {
            // null !== rather than array_filter's default: 0 is a legitimate bound.
            $bounds = array_filter($bounds, static fn (mixed $v): bool => null !== $v && '' !== $v);
            if ([] !== $bounds) {
                $query[$key] = $bounds;
            }
        }

        // Shared scope of the three aggregates above; the filter defaults the states to `paid`.
        $options = array_filter([
            'channel' => $channel,
            'paymentStates' => self::stringList($orderPaymentStates),
        ], static fn (mixed $v): bool => null !== $v && '' !== $v && [] !== $v);
        if ([] !== $options) {
            $query['orderAggregate'] = $options;
        }

        // An unsupported sort field is ignored rather than sent: silence beats a 400.
        if (null !== $sort && \in_array($sort, self::SORTABLE, true)) {
            $query['order'] = [$sort => 'asc' === $direction ? 'asc' : 'desc'];
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::Customer), $query, self::leanHeaders());
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            if (\is_array($member)) {
                $items[] = CustomerSummaryOutput::fromPayload($member);
            }
        }

        return $pager->envelope($items, $total);
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
