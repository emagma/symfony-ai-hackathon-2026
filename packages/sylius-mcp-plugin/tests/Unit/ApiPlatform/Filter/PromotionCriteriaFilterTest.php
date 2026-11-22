<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\PromotionCriteriaFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Promotion;

#[CoversClass(PromotionCriteriaFilter::class)]
final class PromotionCriteriaFilterTest extends OrderFilterTestCase
{
    public function testItIsANoOpOutsideLeanMode(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');
        $qb->expects(self::never())->method('innerJoin');

        $this->filter(lean: false)->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['name' => 'summer', 'channel' => 'WEB', 'activeAt' => '2026-07-01'],
        ]);
    }

    public function testItIgnoresTheVendorFilterKeys(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['coupons.code' => 'XMAS', 'order' => ['priority' => 'desc']],
        ]);
    }

    public function testItMatchesTheNameCaseInsensitively(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['name' => 'SuMMer'],
        ]);

        self::assertCount(1, $captured['wheres']);
        self::assertStringStartsWith('LOWER(o.name) LIKE :', $captured['wheres'][0]);
        self::assertSame(['%summer%'], array_values($captured['params']));
    }

    public function testItEscapesTheLikeWildcardsInTheNeedle(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        // "50%" is what a merchant types to find the half-price campaign; left
        // raw it is a wildcard and matches every promotion.
        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['name' => '50%_off'],
        ]);

        self::assertSame(['%50\%\_off%'], array_values($captured['params']));
    }

    public function testItSearchesTheCodeToo(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['code' => 'SUM'],
        ]);

        self::assertStringStartsWith('LOWER(o.code) LIKE :', $captured['wheres'][0]);
        self::assertSame(['%sum%'], array_values($captured['params']));
    }

    public function testItJoinsTheChannelsAndDeduplicates(): void
    {
        $qb = $this->mockQueryBuilder();
        // channels is a to-many association: a promotion on three channels
        // would otherwise come back three times.
        $qb->expects(self::once())->method('distinct')->willReturnSelf();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['channel' => 'WEB'],
        ]);

        self::assertCount(1, $captured['joins']);
        self::assertSame('inner', $captured['joins'][0]['kind']);
        self::assertSame('o.channels', $captured['joins'][0]['target']);
        self::assertSame(['WEB'], array_values($captured['params']));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function flagProvider(): array
    {
        return [
            'couponBased true' => ['couponBased', true],
            'couponBased false' => ['couponBased', false],
            'exclusive true' => ['exclusive', true],
            'exclusive false' => ['exclusive', false],
        ];
    }

    #[DataProvider('flagProvider')]
    public function testItFiltersOnTheBooleanFlags(string $key, bool $value): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => [$key => $value ? 'true' : 'false'],
        ]);

        self::assertStringStartsWith(sprintf('o.%s = :', $key), $captured['wheres'][0]);
        self::assertSame([$value], array_values($captured['params']));
    }

    public function testItTreatsANullBoundAsUnbounded(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['activeAt' => '2026-07-15'],
        ]);

        self::assertCount(1, $captured['wheres']);
        // A promotion with neither date runs forever and must stay in.
        self::assertStringContainsString('o.startsAt IS NULL OR o.startsAt <=', $captured['wheres'][0]);
        self::assertStringContainsString('o.endsAt IS NULL OR o.endsAt >=', $captured['wheres'][0]);

        $params = array_values($captured['params']);
        self::assertCount(1, $params, 'both bounds share one parameter');
        self::assertInstanceOf(\DateTimeImmutable::class, $params[0]);
        self::assertSame('2026-07-15', $params[0]->format('Y-m-d'));
    }

    public function testItIgnoresAnUnparsableActiveAt(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['activeAt' => 'sometime in July'],
        ]);
    }

    public function testItLogsAndSkipsAMalformedValueInsteadOfFailing(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info');

        $filter = new PromotionCriteriaFilter($this->leanDetector(true), $this->managerRegistry(), $logger);
        $filter->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => ['name' => '   ', 'couponBased' => 'maybe'],
        ]);
    }

    public function testItCombinesEveryCriterion(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->method('distinct')->willReturnSelf();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Promotion::class, null, [
            'filters' => [
                'name' => 'summer',
                'channel' => 'WEB',
                'couponBased' => 'true',
                'activeAt' => '2026-07-15',
            ],
        ]);

        self::assertCount(4, $captured['wheres']);
        self::assertCount(1, $captured['joins']);
    }

    public function testTheDescriptionDocumentsEveryKeyAsLeanOnly(): void
    {
        $description = $this->filter()->getDescription(Promotion::class);

        self::assertSame(
            ['name', 'code', 'channel', 'couponBased', 'exclusive', 'activeAt'],
            array_keys($description),
        );
        foreach ($description as $key => $meta) {
            self::assertStringStartsWith('[Lean mode only', $meta['description'], $key);
        }
    }

    private function filter(bool $lean = true): PromotionCriteriaFilter
    {
        return new PromotionCriteriaFilter(
            $this->leanDetector($lean),
            $this->managerRegistry(),
            $this->silentLogger(),
        );
    }
}
