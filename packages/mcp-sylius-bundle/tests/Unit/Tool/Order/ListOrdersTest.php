<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Order;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Order\SearchOrders;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchOrders::class)]
final class ListOrdersTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchOrders::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_orders', $attributes[0]->newInstance()->name);
    }

    public function testItSendsOnlyPaginationByDefault(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client));
    }

    public function testItMapsTheScalarFiltersToTheVendorFilterNames(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', [
                'channel.code' => 'FASHION_WEB',
                'currencyCode' => 'EUR',
                'customer.id' => '42',
                'items.productName' => 'Mug',
                'shipments.method.code' => 'ups',
                'page' => 1,
                'itemsPerPage' => 30,
            ], ['Prefer' => 'respond-for=lean'])
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchOrders($client),
            channel: 'FASHION_WEB',
            currency: 'EUR',
            customerId: '42',
            productName: 'Mug',
            shippingMethod: 'ups',
        );
    }

    public function testItSendsTheDateRangeAsANestedFilter(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['after' => '2026-01-01', 'before' => '2026-01-31'], $q['checkoutCompletedAt']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), completedFrom: '2026-01-01', completedTo: '2026-01-31');
    }

    public function testItSendsOnlyTheProvidedDateBound(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['after' => '2026-01-01'], $q['checkoutCompletedAt']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), completedFrom: '2026-01-01');
    }

    public function testItSendsTheTotalRangeAsANestedFilter(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['gte' => 5000, 'lte' => 20000], $q['total']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), totalMin: 5000, totalMax: 20000);
    }

    public function testItKeepsAZeroTotalBound(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                // 0 is a meaningful lower bound: array_filter's default would drop it.
                self::assertSame(['gte' => 0], $q['total']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), totalMin: 0);
    }

    public function testItOmitsEmptyStringFilters(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), channel: '', currency: '', completedFrom: '');
    }

    public function testItSendsTheStateFiltersAsLists(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['new'], $q['state']);
                self::assertSame(['awaiting_payment', 'partially_paid'], $q['paymentState']);
                self::assertSame(['ready'], $q['shippingState']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchOrders($client),
            state: ['new'],
            paymentState: ['awaiting_payment', 'partially_paid'],
            shippingState: ['ready'],
        );
    }

    public function testItDropsEmptyAndDuplicateStateEntries(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['new'], $q['state']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), state: ['new', '', 'new']);
    }

    public function testItOmitsAnEmptyStateList(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), state: [], paymentState: []);
    }

    public function testItSendsThePartialSearches(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame('42', $q['number']);
                self::assertSame('ada@example.com', $q['customerEmail']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), number: '42', customerEmail: 'ada@example.com');
    }

    public function testItSendsTheTotalBreakdownRanges(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['gte' => 1000], $q['itemsTotal']);
                self::assertSame(['gte' => 0, 'lte' => 0], $q['adjustmentsTotal']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchOrders($client),
            itemsTotalMin: 1000,
            adjustmentsTotalMin: 0,
            adjustmentsTotalMax: 0,
        );
    }

    public function testItSendsTheSortAsANestedFilter(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['by' => 'total', 'direction' => 'desc'], $q['sort']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), sort: 'total', direction: 'desc');
    }

    public function testItSendsTheSortWithoutADirection(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                // The filter defaults to desc server-side.
                self::assertSame(['by' => 'checkoutCompletedAt'], $q['sort']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), sort: 'checkoutCompletedAt');
    }

    public function testItOmitsTheSortWhenNoFieldIsGiven(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), sort: '', direction: 'desc');
    }

    public function testItSendsTheItemCountRanges(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['gte' => 3], $q['itemsCount']);
                self::assertSame(['gte' => 1, 'lte' => 1], $q['unitsCount']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), itemsCountMin: 3, unitsCountMin: 1, unitsCountMax: 1);
    }

    public function testItSendsTheContentFiltersAsCodeLists(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static function (array $q): bool {
                self::assertSame(['MUG', 'TSHIRT'], $q['containsProduct']);
                self::assertSame(['MUG_BLUE'], $q['containsVariant']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchOrders($client),
            containsProduct: ['MUG', 'TSHIRT'],
            containsVariant: ['MUG_BLUE'],
        );
    }

    public function testItOmitsEmptyContentFilters(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), containsProduct: [], containsVariant: ['']);
    }

    public function testItProjectsMembersToTheLeanShape(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([[
            'tokenValue' => 'TOKEN123',
            'number' => '000000042',
            'state' => 'new',
            'paymentState' => 'awaiting_payment',
            'shippingState' => 'ready',
            'total' => 12500,
            'currencyCode' => 'EUR',
            'checkoutCompletedAt' => '2026-01-15T10:00:00+00:00',
            'channel' => '/api/v2/admin/channels/FASHION_WEB',
            'customer' => '/api/v2/admin/customers/42',
        ]], total: 1));

        $result = $this->call(new SearchOrders($client));

        self::assertSame([[
            'tokenValue' => 'TOKEN123',
            'number' => '000000042',
            'state' => 'new',
            'paymentState' => 'awaiting_payment',
            'shippingState' => 'ready',
            'total' => 12500,
            'currencyCode' => 'EUR',
            'checkoutCompletedAt' => '2026-01-15T10:00:00+00:00',
            'channelCode' => 'FASHION_WEB',
            'customerId' => '42',
        ]], $result['items']);
    }

    public function testItToleratesAGuestOrderWithoutCustomer(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['tokenValue' => 'TOKEN123'],
        ], total: 1));

        $result = $this->call(new SearchOrders($client));

        self::assertSame('TOKEN123', $result['items'][0]['tokenValue']);
        self::assertNull($result['items'][0]['customerId']);
        self::assertNull($result['items'][0]['total']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('orders', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchOrders($client), itemsPerPage: 999);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 30, ['tokenValue' => 'T']),
            total: 87,
        ));

        $result = $this->call(new SearchOrders($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(87, $result['total']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 10, ['tokenValue' => 'T']),
            total: 10,
        ));

        self::assertFalse($this->call(new SearchOrders($client), itemsPerPage: 30)['hasMore']);
    }
}
