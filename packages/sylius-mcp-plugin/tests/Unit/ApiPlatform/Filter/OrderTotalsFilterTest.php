<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\OrderTotalsFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Order;

#[CoversClass(OrderTotalsFilter::class)]
final class OrderTotalsFilterTest extends OrderFilterTestCase
{
    public function testItIsANoOpOutsideLeanMode(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        // A key the filter actually handles, otherwise this passes for the
        // wrong reason.
        $this->filter(lean: false)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['itemsTotal' => ['gte' => 100]],
        ]);
    }

    public function testItLeavesTheGrandTotalToTheVendorRangeFilter(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        // `total` is already covered by sylius_api.range_filter.admin.order.
        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['total' => ['gte' => 100]],
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fieldProvider(): array
    {
        return [
            'items total' => ['itemsTotal'],
            'adjustments total' => ['adjustmentsTotal'],
        ];
    }

    #[DataProvider('fieldProvider')]
    public function testItAppliesBothBounds(string $field): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => [$field => ['gte' => 1000, 'lte' => 5000]],
        ]);

        self::assertCount(2, $captured['wheres']);
        self::assertStringStartsWith(sprintf('o.%s >= :', $field), $captured['wheres'][0]);
        self::assertStringStartsWith(sprintf('o.%s <= :', $field), $captured['wheres'][1]);
        self::assertSame([1000, 5000], array_values($captured['params']));
    }

    public function testItRefusesTheComputedTotals(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        // taxTotal and shippingTotal look like Order fields but are getters
        // summing adjustments across the order, its items and their units.
        // Emitting DQL for them is a Doctrine semantical error, and a subquery
        // over order-level adjustments alone would under-count.
        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['taxTotal' => ['gte' => 100], 'shippingTotal' => ['lte' => 500]],
        ]);
    }

    public function testItOnlyTargetsMappedColumns(): void
    {
        $mapped = ['number', 'notes', 'state', 'checkoutCompletedAt', 'itemsTotal',
            'adjustmentsTotal', 'total', 'createdAt', 'updatedAt', 'id', 'currencyCode',
            'localeCode', 'checkoutState', 'paymentState', 'shippingState',
            'createdByGuest', 'tokenValue', 'customerIp'];

        $reflection = new \ReflectionClass(OrderTotalsFilter::class);
        /** @var list<string> $fields */
        $fields = $reflection->getConstant('FIELDS');

        foreach ($fields as $field) {
            self::assertContains($field, $mapped, sprintf('%s is not a Doctrine column on Order', $field));
        }
    }

    public function testItAppliesOnlyTheGivenBound(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['adjustmentsTotal' => ['gte' => 2000]],
        ]);

        self::assertCount(1, $captured['wheres']);
        self::assertStringContainsString('>=', $captured['wheres'][0]);
    }

    public function testItKeepsAZeroBound(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        // "orders with no shipping charge" is a legitimate question.
        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['adjustmentsTotal' => ['lte' => 0]],
        ]);

        self::assertSame([0], array_values($captured['params']));
    }

    public function testItCoercesNumericStrings(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['adjustmentsTotal' => ['gte' => '1500']],
        ]);

        self::assertSame([1500], array_values($captured['params']));
    }

    public function testItIgnoresNonNumericBounds(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['adjustmentsTotal' => ['gte' => 'plenty']],
        ]);
    }

    public function testItLogsAndSkipsAScalarValue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('[gte|lte] bounds'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['adjustmentsTotal' => '1500'],
        ]);
    }

    public function testItDocumentsEveryBoundAsLeanOnly(): void
    {
        $description = $this->filter()->getDescription(Order::class);

        self::assertSame([
            'itemsTotal[gte]', 'itemsTotal[lte]',
            'adjustmentsTotal[gte]', 'adjustmentsTotal[lte]',
        ], array_keys($description));
        foreach ($description as $key => $meta) {
            self::assertStringContainsString('Lean mode only', $meta['description'], $key);
            self::assertStringContainsString('Minor units', $meta['description'], $key);
        }
    }

    private function filter(bool $lean = true, ?LoggerInterface $logger = null): OrderTotalsFilter
    {
        return new OrderTotalsFilter(
            $this->leanDetector($lean),
            $this->managerRegistry(),
            $logger ?? $this->silentLogger(),
        );
    }
}
