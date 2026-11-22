<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\CustomerOrderAggregateFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;

#[CoversClass(CustomerOrderAggregateFilter::class)]
final class CustomerOrderAggregateFilterTest extends OrderFilterTestCase
{
    public function testItIsANoOpOutsideLeanMode(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(lean: false)->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['ordersCount' => ['gte' => 2]],
        ]);
    }

    public function testItIgnoresUnrelatedKeys(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['email' => 'ada', 'orderAggregate' => ['channel' => 'WEB']],
        ]);
    }

    public function testItRefusesAMonetaryThresholdWithoutAChannel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('orderAggregate[channel] is required'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        // Summing totals across currencies would add cents to yen.
        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['ordersTotal' => ['gte' => 50000]],
        ]);
    }

    public function testItSumsTotalsWithinOneChannel(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => [
                'ordersTotal' => ['gte' => 50000],
                'orderAggregate' => ['channel' => 'FASHION_WEB'],
            ],
        ]);

        self::assertCount(1, $captured['wheres']);
        $where = $captured['wheres'][0];

        self::assertStringContainsString('SELECT COALESCE(SUM(', $where);
        self::assertStringContainsString('.total), 0)', $where);
        self::assertStringContainsString('FROM ' . Order::class, $where);
        self::assertStringContainsString('.customer = o', $where);
        self::assertStringContainsString('JOIN', $where);
        self::assertStringContainsString('.code = :', $where);
        self::assertStringContainsString(') >= :', $where);

        self::assertContains('FASHION_WEB', array_values($captured['params']));
        self::assertContains(50000, array_values($captured['params']));
    }

    public function testItAlwaysExcludesCartsAndDefaultsToPaidOrders(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['ordersCount' => ['gte' => 1]],
        ]);

        $where = $captured['wheres'][0];
        // StateBasedExtension guards the order collection, not this subquery.
        self::assertStringContainsString('.state != :', $where);
        self::assertStringContainsString('.paymentState IN (:', $where);

        $params = array_values($captured['params']);
        self::assertContains('cart', $params);
        self::assertContains(['paid'], $params);
    }

    public function testItAcceptsAnExplicitPaymentStateList(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => [
                'ordersCount' => ['gte' => 1],
                'orderAggregate' => ['paymentStates' => ['paid', 'partially_refunded']],
            ],
        ]);

        self::assertContains(['paid', 'partially_refunded'], array_values($captured['params']));
    }

    public function testItFallsBackToPaidWhenTheStateListIsJunk(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => [
                'ordersCount' => ['gte' => 1],
                'orderAggregate' => ['paymentStates' => ['', 42, null]],
            ],
        ]);

        self::assertContains(['paid'], array_values($captured['params']));
    }

    public function testItCountsOrdersWithoutRequiringAChannel(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['ordersCount' => ['gte' => 2, 'lte' => 10]],
        ]);

        self::assertCount(2, $captured['wheres']);
        self::assertStringContainsString('SELECT COUNT(', $captured['wheres'][0]);
        // No channel given: no join, so the count spans every channel.
        self::assertStringNotContainsString('JOIN', $captured['wheres'][0]);
    }

    public function testItFindsDormantCustomersByTheirLastOrderDate(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['lastOrder' => ['before' => '2026-01-01']],
        ]);

        $where = $captured['wheres'][0];
        self::assertStringContainsString('SELECT MAX(', $where);
        self::assertStringContainsString('.checkoutCompletedAt)', $where);
        self::assertStringContainsString(') <= :', $where);

        $date = array_values(array_filter(
            $captured['params'],
            static fn (mixed $p): bool => $p instanceof \DateTimeImmutable,
        ));
        self::assertSame('2026-01-01', $date[0]->format('Y-m-d'));
    }

    public function testItIgnoresAnUnparsableDate(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['lastOrder' => ['before' => 'not a date']],
        ]);
    }

    public function testItRejectsADateThatPhpParsesWithWarnings(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        // `new DateTimeImmutable('a while ago')` does NOT throw - it returns
        // NOW with a warning. Accepting it would turn "customers dormant since
        // X" into "customers dormant since now", i.e. the entire base.
        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['lastOrder' => ['before' => 'a while ago']],
        ]);
    }

    public function testItIgnoresNonNumericBounds(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['ordersCount' => ['gte' => 'lots']],
        ]);
    }

    public function testItLogsAndSkipsAScalarValue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('expected bounds'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['ordersCount' => 3],
        ]);
    }

    public function testItNeverGroupsTheMainQuery(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('groupBy');
        $qb->expects(self::never())->method('addGroupBy');
        $qb->expects(self::never())->method('having');
        $qb->expects(self::never())->method('andHaving');
        $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => [
                'ordersTotal' => ['gte' => 1000],
                'ordersCount' => ['gte' => 2],
                'lastOrder' => ['after' => '2026-01-01'],
                'orderAggregate' => ['channel' => 'WEB'],
            ],
        ]);
    }

    public function testCombinedAggregatesUseDistinctSubqueryAliases(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => [
                'ordersTotal' => ['gte' => 1000, 'lte' => 90000],
                'ordersCount' => ['gte' => 2],
                'orderAggregate' => ['channel' => 'WEB'],
            ],
        ]);

        $aliases = [];
        foreach ($captured['wheres'] as $where) {
            preg_match_all('/FROM \S+ (\w+)/', $where, $matches);
            $aliases = [...$aliases, ...$matches[1]];
        }
        self::assertCount(3, $aliases);
        self::assertSame($aliases, array_values(array_unique($aliases)));
    }

    public function testItDocumentsEveryKeyAsLeanOnly(): void
    {
        $description = $this->filter()->getDescription(Customer::class);

        self::assertSame([
            'ordersTotal[gte]', 'ordersTotal[lte]',
            'ordersCount[gte]', 'ordersCount[lte]',
            'lastOrder[after]', 'lastOrder[before]',
            'orderAggregate[channel]', 'orderAggregate[paymentStates][]',
        ], array_keys($description));

        foreach ($description as $key => $meta) {
            self::assertStringContainsString('Lean mode only', $meta['description'], $key);
        }
        self::assertStringContainsString('REQUIRES orderAggregate[channel]', $description['ordersTotal[gte]']['description']);
    }

    private function filter(bool $lean = true, ?LoggerInterface $logger = null): CustomerOrderAggregateFilter
    {
        return new CustomerOrderAggregateFilter(
            $this->leanDetector($lean),
            Order::class,
            $this->managerRegistry(),
            $logger ?? $this->silentLogger(),
        );
    }
}
