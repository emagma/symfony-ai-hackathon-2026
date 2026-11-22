<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Customer;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Customer\GetCustomerStatistics;
use Emagma\McpSyliusBundle\Tool\Customer\SearchCustomers;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchCustomers::class)]
#[CoversClass(GetCustomerStatistics::class)]
#[CoversClass(CustomerShaper::class)]
final class CustomerToolsTest extends McpToolTestCase
{
    public function testItSendsOnlyPaginationByDefault(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client));
    }

    public function testItMapsTheFiltersToTheVendorNames(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', [
                'email' => 'ada',
                'firstName' => 'Ada',
                'lastName' => 'Love',
                // The vendor SearchFilter matches the group NAME, not its code.
                'group.name' => 'Wholesale',
                'page' => 1,
                'itemsPerPage' => 30,
            ], ['Prefer' => 'respond-for=lean'])
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchCustomers($client),
            email: 'ada',
            firstName: 'Ada',
            lastName: 'Love',
            group: 'Wholesale',
        );
    }

    public function testItSendsTheSortAsTheVendorOrderFilter(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', self::callback(static function (array $q): bool {
                self::assertSame(['createdAt' => 'asc'], $q['order']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), sort: 'createdAt', direction: 'asc');
    }

    public function testItDefaultsTheSortDirectionToDescending(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', self::callback(static function (array $q): bool {
                self::assertSame(['email' => 'desc'], $q['order']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), sort: 'email');
    }

    public function testItIgnoresAnUnsupportedSortField(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            // A 400 mid-conversation costs the model more than an unsorted page.
            ->with('customers', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), sort: 'phoneNumber');
    }

    public function testItSendsTheCohortAndTargetingFilters(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', self::callback(static function (array $q): bool {
                self::assertSame('wholesale', $q['groupCode']);
                self::assertSame(['after' => '2026-01-01', 'before' => '2026-01-31'], $q['createdAt']);
                // Query params travel as strings; the filter parses both forms.
                self::assertSame('true', $q['subscribedToNewsletter']);
                self::assertSame('false', $q['hasAccount']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchCustomers($client),
            groupCode: 'wholesale',
            createdFrom: '2026-01-01',
            createdTo: '2026-01-31',
            subscribedToNewsletter: true,
            hasAccount: false,
        );
    }

    public function testItOmitsTheBooleanFiltersWhenNotGiven(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), subscribedToNewsletter: null, hasAccount: null);
    }

    public function testItSendsOnlyTheProvidedSignupBound(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', self::callback(static function (array $q): bool {
                self::assertSame(['before' => '2026-01-31'], $q['createdAt']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), createdTo: '2026-01-31');
    }

    public function testItSendsTheOrderAggregateFiltersWithTheirSharedScope(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', self::callback(static function (array $q): bool {
                self::assertSame(['gte' => 50000], $q['ordersTotal']);
                self::assertSame(['gte' => 2, 'lte' => 10], $q['ordersCount']);
                self::assertSame(['before' => '2026-01-01'], $q['lastOrder']);
                self::assertSame([
                    'channel' => 'FASHION_WEB',
                    'paymentStates' => ['paid', 'partially_refunded'],
                ], $q['orderAggregate']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchCustomers($client),
            channel: 'FASHION_WEB',
            orderPaymentStates: ['paid', 'partially_refunded'],
            ordersTotalMin: 50000,
            ordersCountMin: 2,
            ordersCountMax: 10,
            lastOrderBefore: '2026-01-01',
        );
    }

    public function testItSendsTheChannelScopeAloneWhenNoStatesAreGiven(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', self::callback(static function (array $q): bool {
                // The filter defaults the states to `paid` server-side.
                self::assertSame(['channel' => 'FASHION_WEB'], $q['orderAggregate']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), channel: 'FASHION_WEB', ordersTotalMin: 50000);
    }

    public function testItOmitsTheAggregateScopeEntirelyWhenUnused(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), channel: '', orderPaymentStates: []);
    }

    public function testItKeepsAZeroOrderCountBound(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', self::callback(static function (array $q): bool {
                self::assertSame(['lte' => 0], $q['ordersCount']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), ordersCountMax: 0);
    }

    public function testTheListingCarriesNoPersonalDetail(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([[
            'id' => 42,
            'email' => 'ada@example.com',
            'fullName' => 'Ada Lovelace',
            'createdAt' => '2026-01-01T09:00:00+00:00',
            'phoneNumber' => '+33123456789',
            'birthday' => '1815-12-10',
            'gender' => 'f',
        ]], total: 1));

        $result = $this->call(new SearchCustomers($client));

        self::assertSame([[
            'id' => '42',
            'email' => 'ada@example.com',
            'fullName' => 'Ada Lovelace',
            'createdAt' => '2026-01-01T09:00:00+00:00',
            'untrustedContent' => ['fields' => ['email', 'fullName']],
        ]], $result['items']);
        self::assertStringNotContainsString('+33123456789', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomers($client), itemsPerPage: 999);
    }

    public function testStatisticsAreReportedPerChannel(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customers/42/statistics', [], [])
            ->willReturn([
                'allOrdersCount' => 5,
                'perChannelsStatistics' => [
                    [
                        'channel' => '/api/v2/admin/channels/FASHION_WEB',
                        'ordersCount' => 3,
                        'ordersValue' => 45000,
                        'averageOrderValue' => 15000,
                    ],
                    [
                        'channel' => '/api/v2/admin/channels/US_WEB',
                        'ordersCount' => 2,
                        'ordersValue' => 20000,
                        'averageOrderValue' => 10000,
                    ],
                ],
            ]);

        $result = $this->call(new GetCustomerStatistics($client), '42');

        self::assertTrue($result['found']);
        self::assertSame(5, $result['ordersCount']);
        // Never summed: each channel has its own currency.
        self::assertSame([
            ['channelCode' => 'FASHION_WEB', 'ordersCount' => 3, 'ordersValue' => 45000, 'averageOrderValue' => 15000],
            ['channelCode' => 'US_WEB', 'ordersCount' => 2, 'ordersValue' => 20000, 'averageOrderValue' => 10000],
        ], $result['perChannel']);
    }

    public function testStatisticsReadTheHydraCollectionTheShopAnswers(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn([
            '@type' => 'CustomerStatistics',
            'allOrdersCount' => 2,
            'perChannelsStatistics' => [
                '@type' => 'hydra:Collection',
                'hydra:totalItems' => 1,
                'hydra:member' => [
                    ['channel' => ['code' => 'FASHION_WEB'], 'ordersCount' => 2, 'ordersValue' => 9000, 'averageOrderValue' => 4500],
                ],
            ],
        ]);

        $result = $this->call(new GetCustomerStatistics($client), '42');

        self::assertSame([['channelCode' => 'FASHION_WEB', 'ordersCount' => 2, 'ordersValue' => 9000, 'averageOrderValue' => 4500]], $result['perChannel']);
    }

    public function testStatisticsToleratesAnEmptyBreakdown(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['allOrdersCount' => 0]);

        $result = $this->call(new GetCustomerStatistics($client), '42');

        self::assertSame(0, $result['ordersCount']);
        self::assertSame([], $result['perChannel']);
    }

    public function testStatisticsReturnsNotFoundOn404(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        self::assertSame(['found' => false, 'id' => '99'], $this->call(new GetCustomerStatistics($client), '99'));
    }
}
