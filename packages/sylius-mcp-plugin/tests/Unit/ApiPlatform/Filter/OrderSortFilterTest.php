<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\OrderSortFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Order;

#[CoversClass(OrderSortFilter::class)]
final class OrderSortFilterTest extends OrderFilterTestCase
{
    public function testItIsANoOpOutsideLeanMode(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('addOrderBy');

        $this->filter(lean: false)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['sort' => ['by' => 'total']],
        ]);
    }

    public function testItDoesNotTouchTheVendorOrderKey(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('addOrderBy');

        // `order` belongs to the vendor OrderFilter; two filters writing
        // ORDER BY from the same key would fight.
        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['order' => ['number' => 'asc']],
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sortableProvider(): array
    {
        return [
            'completion date' => ['checkoutCompletedAt'],
            'total' => ['total'],
            'number' => ['number'],
        ];
    }

    #[DataProvider('sortableProvider')]
    public function testItOrdersByEachWhitelistedField(string $field): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['sort' => ['by' => $field, 'direction' => 'asc']],
        ]);

        self::assertSame([['sort' => 'o.' . $field, 'order' => 'asc']], $captured['orderBy']);
    }

    public function testItDefaultsToDescending(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['sort' => ['by' => 'total']],
        ]);

        self::assertSame('desc', $captured['orderBy'][0]['order']);
    }

    public function testItFallsBackToDescendingOnAnUnknownDirection(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['sort' => ['by' => 'total', 'direction' => 'sideways']],
        ]);

        self::assertSame('desc', $captured['orderBy'][0]['order']);
    }

    public function testItAcceptsAnUppercaseDirection(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['sort' => ['by' => 'number', 'direction' => 'ASC']],
        ]);

        self::assertSame('asc', $captured['orderBy'][0]['order']);
    }

    public function testItRefusesAFieldOutsideTheWhitelist(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('must be one of'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('addOrderBy');

        // An arbitrary property would be a DQL injection surface.
        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['sort' => ['by' => 'customer.emailCanonical']],
        ]);
    }

    public function testItIgnoresAMalformedSortValue(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('addOrderBy');

        $this->filter()->apply($qb, $this->mockQng(), Order::class, null, [
            'filters' => ['sort' => 'total'],
        ]);
    }

    public function testItDocumentsBothKeysAsLeanOnly(): void
    {
        $description = $this->filter()->getDescription(Order::class);

        self::assertSame(['sort[by]', 'sort[direction]'], array_keys($description));
        self::assertSame(
            ['checkoutCompletedAt', 'total', 'number'],
            $description['sort[by]']['schema']['enum'],
        );
        foreach ($description as $key => $meta) {
            self::assertStringContainsString('Lean mode only', $meta['description'], $key);
        }
    }

    private function filter(bool $lean = true, ?LoggerInterface $logger = null): OrderSortFilter
    {
        return new OrderSortFilter(
            $this->leanDetector($lean),
            $this->managerRegistry(),
            $logger ?? $this->silentLogger(),
        );
    }
}
