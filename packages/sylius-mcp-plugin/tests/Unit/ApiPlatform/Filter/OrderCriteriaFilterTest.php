<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\OrderCriteriaFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Order;

#[CoversClass(OrderCriteriaFilter::class)]
final class OrderCriteriaFilterTest extends OrderFilterTestCase
{
    public function testItIsANoOpOutsideLeanMode(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');
        $qb->expects(self::never())->method('innerJoin');

        $this->filter(lean: false)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => [
                'state' => ['new'],
                'number' => '42',
                'customerEmail' => 'ada@example.com',
            ],
        ]);
    }

    public function testItIgnoresUnrelatedKeys(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['channel.code' => 'WEB', 'total' => ['gte' => 100]],
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function stateKeyProvider(): array
    {
        return [
            'order state' => ['state', 'o.state'],
            'payment state' => ['paymentState', 'o.paymentState'],
            'shipping state' => ['shippingState', 'o.shippingState'],
        ];
    }

    #[DataProvider('stateKeyProvider')]
    public function testItFiltersEachStateMachineWithIn(string $key, string $expectedColumn): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => [$key => ['paid', 'refunded']],
        ]);

        self::assertCount(1, $captured['wheres']);
        self::assertStringStartsWith($expectedColumn . ' IN (:', $captured['wheres'][0]);
        self::assertSame([['paid', 'refunded']], array_values($captured['params']));
    }

    public function testItAcceptsASingleStateAsAScalar(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['paymentState' => 'awaiting_payment'],
        ]);

        self::assertSame([['awaiting_payment']], array_values($captured['params']));
    }

    public function testItDropsNonStringAndEmptyStateEntries(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['state' => ['new', '', 42, null, 'new']],
        ]);

        // Deduplicated, and the junk never reaches the query.
        self::assertSame([['new']], array_values($captured['params']));
    }

    public function testItSkipsAStateFilterWithNoUsableValue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('no usable value'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['state' => []],
        ]);
    }

    public function testItMatchesTheOrderNumberPartiallyAndCaseInsensitively(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['number' => '42'],
        ]);

        self::assertStringStartsWith('LOWER(o.number) LIKE :', $captured['wheres'][0]);
        // Zero-padded numbers mean the needle has to be unanchored.
        self::assertSame(['%42%'], array_values($captured['params']));
    }

    public function testItEscapesLikeWildcardsInUserInput(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['number' => '10%_'],
        ]);

        // A bare % would silently match everything.
        self::assertSame(['%10\\%\\_%'], array_values($captured['params']));
    }

    public function testItJoinsTheCustomerToSearchTheCanonicalEmail(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['customerEmail' => 'Ada@Example.COM'],
        ]);

        self::assertCount(1, $captured['joins']);
        self::assertSame('o.customer', $captured['joins'][0]['target']);
        self::assertStringContainsString('.emailCanonical LIKE :', $captured['wheres'][0]);
        // emailCanonical is already lowercased by Sylius, so the needle is too
        // and no function wraps the column.
        self::assertSame(['%ada@example.com%'], array_values($captured['params']));
        self::assertStringNotContainsString('LOWER(', $captured['wheres'][0]);
    }

    public function testItSkipsABlankCustomerEmail(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('customerEmail'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('innerJoin');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['customerEmail' => '   '],
        ]);
    }

    public function testItCombinesSeveralCriteria(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => [
                'state' => ['new'],
                'paymentState' => ['awaiting_payment'],
                'number' => '42',
            ],
        ]);

        self::assertCount(3, $captured['wheres']);
    }

    public function testItDocumentsEveryKeyAsLeanOnly(): void
    {
        $description = $this->filter()->getDescription(Order::class);

        self::assertSame(
            ['state[]', 'paymentState[]', 'shippingState[]', 'number', 'customerEmail'],
            array_keys($description),
        );
        foreach ($description as $key => $meta) {
            self::assertStringContainsString('Lean mode only', $meta['description'], $key);
        }
    }

    private function filter(bool $lean = true, ?LoggerInterface $logger = null): OrderCriteriaFilter
    {
        return new OrderCriteriaFilter(
            $this->leanDetector($lean),
            $this->managerRegistry(),
            $logger ?? $this->silentLogger(),
        );
    }
}
