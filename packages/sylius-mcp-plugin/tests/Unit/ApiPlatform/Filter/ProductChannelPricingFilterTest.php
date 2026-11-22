<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\ProductChannelPricingFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(ProductChannelPricingFilter::class)]
final class ProductChannelPricingFilterTest extends TestCase
{
    public function testItShortCircuitsWhenLeanDetectorReturnsFalse(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');
        $qb->expects(self::never())->method('andWhere');
        $qb->expects(self::never())->method('distinct');

        $filter = $this->filter(returns: false);
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['price' => ['channel' => 'WEB', 'gte' => 100, 'lte' => 500]],
        ]);
    }

    public function testItIgnoresPriceFilterWhenChannelIsMissing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('channel code is required'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');

        $filter = $this->filter(returns: true, logger: $logger);
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['price' => ['gte' => 100, 'lte' => 500]],
        ]);
    }

    public function testItIgnoresWhenChannelIsPresentButNoBounds(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');

        $filter = $this->filter(returns: true);
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['price' => ['channel' => 'WEB']],
        ]);
    }

    public function testItIgnoresUnrelatedFilterKeys(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');

        $filter = $this->filter(returns: true);
        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['code' => 'AAA']]);
    }

    public function testItJoinsVariantsAndChannelPricingsWithCompositeWith(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->captureQueryBuilderCalls($qb);

        $filter = $this->filter(returns: true);
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['price' => ['channel' => 'WEB', 'gte' => 100, 'lte' => 500]],
        ]);

        /** @var list<array{target: string, alias: string, condition: string}> $joins */
        $joins = $captured['joins'];
        self::assertCount(2, $joins, 'variants + channelPricings');
        self::assertSame('o.variants', $joins[0]['target']);

        self::assertStringEndsWith('.channelPricings', $joins[1]['target']);
        self::assertStringContainsString('channelCode = :', $joins[1]['condition']);
        self::assertStringContainsString('.price >= :', $joins[1]['condition']);
        self::assertStringContainsString('.price <= :', $joins[1]['condition']);

        /** @var list<string> $wheres */
        $wheres = $captured['wheres'];
        self::assertCount(1, $wheres);
        self::assertStringEndsWith('.id IS NOT NULL', $wheres[0]);
    }

    public function testItCallsDistinctOnQueryBuilder(): void
    {
        $qb = $this->mockQueryBuilder();
        $this->captureQueryBuilderCalls($qb);
        $qb->expects(self::once())->method('distinct')->willReturnSelf();

        $filter = $this->filter(returns: true);
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['price' => ['channel' => 'WEB', 'lte' => 500]],
        ]);
    }

    public function testItOmitsGteWhenOnlyLteProvided(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->captureQueryBuilderCalls($qb);

        $filter = $this->filter(returns: true);
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['price' => ['channel' => 'WEB', 'lte' => 500]],
        ]);

        /** @var list<array{target: string, alias: string, condition: string}> $joins */
        $joins = $captured['joins'];
        self::assertCount(2, $joins);
        self::assertStringNotContainsString('>=', $joins[1]['condition']);
        self::assertStringContainsString('<= :', $joins[1]['condition']);
    }

    public function testGetDescriptionMarksChannelAsRequired(): void
    {
        $filter = $this->filter(returns: true);
        $description = $filter->getDescription(Product::class);

        self::assertArrayHasKey('price[channel]', $description);
        self::assertTrue($description['price[channel]']['required']);
        self::assertFalse($description['price[gte]']['required']);
        self::assertFalse($description['price[lte]']['required']);
    }

    // ---- helpers ----

    /**
     * @return \ArrayObject<string, array<int|string, mixed>>
     */
    private function captureQueryBuilderCalls(MockObject&QueryBuilder $qb): \ArrayObject
    {
        /** @var \ArrayObject<string, array<int|string, mixed>> $captured */
        $captured = new \ArrayObject(['joins' => [], 'wheres' => [], 'params' => []]);

        $qb->method('leftJoin')->willReturnCallback(
            function (...$args) use ($captured, $qb): QueryBuilder {
                $joins = $captured['joins'];
                $joins[] = [
                    'target' => (string) ($args[0] ?? ''),
                    'alias' => (string) ($args[1] ?? ''),
                    'condition' => (string) ($args[3] ?? ''),
                ];
                $captured['joins'] = $joins;

                return $qb;
            },
        );
        $qb->method('andWhere')->willReturnCallback(
            function (...$args) use ($captured, $qb): QueryBuilder {
                $wheres = $captured['wheres'];
                $wheres[] = (string) ($args[0] ?? '');
                $captured['wheres'] = $wheres;

                return $qb;
            },
        );
        $qb->method('setParameter')->willReturnCallback(
            function (...$args) use ($captured, $qb): QueryBuilder {
                $params = $captured['params'];
                $params[(string) ($args[0] ?? '')] = $args[1] ?? null;
                $captured['params'] = $params;

                return $qb;
            },
        );

        return $captured;
    }

    private function filter(bool $returns, ?LoggerInterface $logger = null): ProductChannelPricingFilter
    {
        return new ProductChannelPricingFilter(
            $this->mockLeanDetector($returns),
            $this->createMock(ManagerRegistry::class),
            $logger,
        );
    }

    private function mockLeanDetector(bool $returns): LeanRequestDetector
    {
        $stack = new RequestStack();
        $request = Request::create('/');
        if ($returns) {
            $request->headers->set('Prefer', 'respond-for=lean');
        }
        $stack->push($request);

        return new LeanRequestDetector($stack);
    }

    private function mockQueryBuilder(): QueryBuilder&MockObject
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['o']);
        $qb->method('distinct')->willReturnSelf();

        return $qb;
    }

    private function mockQng(): QueryNameGeneratorInterface&MockObject
    {
        $qng = $this->createMock(QueryNameGeneratorInterface::class);
        $counter = 0;
        $qng->method('generateJoinAlias')->willReturnCallback(static function (string $prefix) use (&$counter): string {
            return $prefix . '_' . ++$counter;
        });
        $qng->method('generateParameterName')->willReturnCallback(static function (string $prefix) use (&$counter): string {
            return $prefix . '_' . ++$counter;
        });

        return $qng;
    }
}
