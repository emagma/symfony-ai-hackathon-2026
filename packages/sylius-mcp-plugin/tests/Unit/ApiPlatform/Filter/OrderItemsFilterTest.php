<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\OrderItemsFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;

#[CoversClass(OrderItemsFilter::class)]
final class OrderItemsFilterTest extends OrderFilterTestCase
{
    public function testItIsANoOpOutsideLeanMode(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(lean: false)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['itemsCount' => ['gte' => 2], 'containsProduct' => ['MUG']],
        ]);
    }

    public function testItIgnoresUnrelatedKeys(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['state' => ['new']],
        ]);
    }

    public function testItCountsLinesWithACorrelatedSubquery(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['itemsCount' => ['gte' => 2]],
        ]);

        self::assertCount(1, $captured['wheres']);
        $where = $captured['wheres'][0];

        self::assertStringContainsString('SELECT COUNT(', $where);
        self::assertStringContainsString('FROM ' . OrderItem::class, $where);
        self::assertStringContainsString('.order = o', $where);
        self::assertStringContainsString(') >= :', $where);
        self::assertSame([2], array_values($captured['params']));
    }

    public function testItNeverGroupsTheMainQuery(): void
    {
        $qb = $this->mockQueryBuilder();
        // A GROUP BY on the root entity would make API Platform's pagination
        // COUNT return one row per group, so the reported total would lie.
        $qb->expects(self::never())->method('groupBy');
        $qb->expects(self::never())->method('addGroupBy');
        $qb->expects(self::never())->method('having');
        $qb->expects(self::never())->method('andHaving');
        $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => [
                'itemsCount' => ['gte' => 2, 'lte' => 10],
                'unitsCount' => ['gte' => 3],
                'containsProduct' => ['MUG'],
            ],
        ]);
    }

    public function testItSumsQuantitiesWithACoalescedSubquery(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['unitsCount' => ['lte' => 1]],
        ]);

        $where = $captured['wheres'][0];
        // An order with no lines must compare as 0, not as NULL.
        self::assertStringContainsString('COALESCE(SUM(', $where);
        self::assertStringContainsString('.quantity), 0)', $where);
        self::assertStringContainsString(') <= :', $where);
    }

    public function testItAppliesBothCountBoundsSeparately(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['itemsCount' => ['gte' => 2, 'lte' => 5]],
        ]);

        self::assertCount(2, $captured['wheres']);
        self::assertSame([2, 5], array_values($captured['params']));
    }

    public function testItKeepsAZeroCountBound(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['itemsCount' => ['lte' => 0]],
        ]);

        self::assertSame([0], array_values($captured['params']));
    }

    public function testItIgnoresNonNumericCountBounds(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['itemsCount' => ['gte' => 'many']],
        ]);
    }

    public function testItLogsAndSkipsAScalarCountValue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('[gte|lte] bounds'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['unitsCount' => 3],
        ]);
    }

    public function testItMatchesProductCodesThroughTheVariant(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['containsProduct' => ['MUG', 'TSHIRT']],
        ]);

        $where = $captured['wheres'][0];
        self::assertStringStartsWith('EXISTS (', $where);
        // An order item points at a variant, so the product is one hop further.
        self::assertStringContainsString('.variant ', $where);
        self::assertStringContainsString('.product ', $where);
        self::assertStringContainsString('.code IN (:', $where);
        self::assertSame([['MUG', 'TSHIRT']], array_values($captured['params']));
    }

    public function testItMatchesVariantCodesWithoutJoiningTheProduct(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['containsVariant' => ['MUG_BLUE']],
        ]);

        $where = $captured['wheres'][0];
        self::assertStringContainsString('.variant ', $where);
        self::assertStringNotContainsString('.product ', $where);
        self::assertSame([['MUG_BLUE']], array_values($captured['params']));
    }

    public function testItAcceptsASingleCodeAsAScalar(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['containsProduct' => 'MUG'],
        ]);

        self::assertSame([['MUG']], array_values($captured['params']));
    }

    public function testItDropsJunkCodes(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['containsProduct' => ['MUG', '', '  ', 42, null, 'MUG']],
        ]);

        self::assertSame([['MUG']], array_values($captured['params']));
    }

    public function testItSkipsAContainsFilterWithNoUsableCode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('no usable code'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['containsVariant' => []],
        ]);
    }

    public function testItUsesDistinctSubqueryAliasesWhenCombined(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => [
                'itemsCount' => ['gte' => 2, 'lte' => 5],
                'containsProduct' => ['MUG'],
            ],
        ]);

        // Three subqueries in the same WHERE: reusing an alias would be a
        // Doctrine parse error.
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
        $description = $this->filter()->getDescription(Order::class);

        self::assertSame([
            'itemsCount[gte]', 'itemsCount[lte]',
            'unitsCount[gte]', 'unitsCount[lte]',
            'containsProduct[]', 'containsVariant[]',
        ], array_keys($description));
        foreach ($description as $key => $meta) {
            self::assertStringContainsString('Lean mode only', $meta['description'], $key);
        }
    }

    private function filter(bool $lean = true, ?LoggerInterface $logger = null): OrderItemsFilter
    {
        return new OrderItemsFilter(
            $this->leanDetector($lean),
            OrderItem::class,
            $this->managerRegistry(),
            $logger ?? $this->silentLogger(),
        );
    }
}
