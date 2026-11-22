<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\ProductAttributeValueFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Attribute\Model\AttributeValueInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Product\Model\ProductAttributeInterface;
use Sylius\Component\Product\Repository\ProductAttributeRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(ProductAttributeValueFilter::class)]
final class ProductAttributeValueFilterTest extends TestCase
{
    public function testItShortCircuitsWhenLeanDetectorReturnsFalse(): void
    {
        $leanDetector = $this->mockLeanDetector(returns: false);
        $repository = $this->createMock(ProductAttributeRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneBy');

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');
        $qb->expects(self::never())->method('andWhere');

        $filter = new ProductAttributeValueFilter(
            $leanDetector,
            $repository,
            'en_US',
            $this->createMock(ManagerRegistry::class),
        );

        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['attribute' => ['color' => 'red']]]);
    }

    public function testItDoesNothingWhenFilterArrayIsEmpty(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');
        $qb->expects(self::never())->method('andWhere');

        $filter = $this->filter(returns: true);
        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['attribute' => []]]);
    }

    public function testItIgnoresUnrelatedFilterKeys(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');

        $filter = $this->filter(returns: true);
        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['random' => 'value']]);
    }

    public function testItSkipsUnknownAttributeCode(): void
    {
        $repository = $this->createMock(ProductAttributeRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('Unknown product attribute'), self::callback(static fn (array $ctx): bool => 'mystery' === ($ctx['code'] ?? null)));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');

        $filter = new ProductAttributeValueFilter(
            $this->mockLeanDetector(returns: true),
            $repository,
            'en_US',
            $this->createMock(ManagerRegistry::class),
            $logger,
        );

        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['attribute' => ['mystery' => 'red']]]);
    }

    public function testItAddsJoinAndEqualityClauseForTextAttribute(): void
    {
        $attribute = $this->mockAttribute(AttributeValueInterface::STORAGE_TEXT);
        $qb = $this->mockQueryBuilder();
        $captured = $this->captureQueryBuilderCalls($qb);

        $filter = $this->filter(returns: true, attribute: $attribute);
        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['attribute' => ['color' => 'red']]]);

        self::assertCount(1, $captured['joins'], 'one leftJoin expected');
        self::assertSame('o.attributes', $captured['joins'][0]['target']);
        self::assertStringContainsString('pav', $captured['joins'][0]['alias']);
        self::assertStringContainsString('attribute = :', $captured['joins'][0]['condition']);
        self::assertStringContainsString('localeCode = :', $captured['joins'][0]['condition']);
        self::assertStringContainsString('IS NULL', $captured['joins'][0]['condition']);

        self::assertCount(1, $captured['wheres'], 'one andWhere expected');
        self::assertMatchesRegularExpression('/pav\w*\.text = :/', $captured['wheres'][0]);
    }

    public function testItUsesCastAndLikeForJsonStorageType(): void
    {
        $attribute = $this->mockAttribute(AttributeValueInterface::STORAGE_JSON);
        $qb = $this->mockQueryBuilder();
        $captured = $this->captureQueryBuilderCalls($qb);

        $filter = $this->filter(returns: true, attribute: $attribute);
        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['attribute' => ['material' => 'cotton']]]);

        self::assertCount(1, $captured['wheres']);
        self::assertStringContainsString('CAST(', $captured['wheres'][0]);
        self::assertStringContainsString('.json AS text) LIKE :', $captured['wheres'][0]);

        $jsonParam = array_values(array_filter($captured['params'], static fn (mixed $v): bool => \is_string($v) && str_contains($v, '"cotton"')));
        self::assertNotEmpty($jsonParam, 'CAST LIKE parameter should wrap value in quotes');
    }

    public function testItAddsPresenceCheckForAttributePresent(): void
    {
        $attribute = $this->mockAttribute(AttributeValueInterface::STORAGE_TEXT);
        $qb = $this->mockQueryBuilder();
        $captured = $this->captureQueryBuilderCalls($qb);

        $filter = $this->filter(returns: true, attribute: $attribute);
        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['attribute_present' => ['t_shirt_brand']]]);

        self::assertCount(1, $captured['joins']);
        self::assertCount(1, $captured['wheres']);
        self::assertStringContainsString('.id IS NOT NULL', $captured['wheres'][0]);
    }

    public function testItAppliesGteAndLteForIntegerRange(): void
    {
        $attribute = $this->mockAttribute(AttributeValueInterface::STORAGE_INTEGER);
        $qb = $this->mockQueryBuilder();
        $captured = $this->captureQueryBuilderCalls($qb);

        $filter = $this->filter(returns: true, attribute: $attribute);
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['attribute_range' => ['weight_g' => ['gte' => 100, 'lte' => 500]]],
        ]);

        self::assertCount(2, $captured['wheres'], 'gte + lte = two andWhere calls');
        self::assertStringContainsString('.integer >= :', $captured['wheres'][0]);
        self::assertStringContainsString('.integer <= :', $captured['wheres'][1]);
    }

    public function testItSkipsRangeOnTextStorageWithWarning(): void
    {
        $attribute = $this->mockAttribute(AttributeValueInterface::STORAGE_TEXT);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('non-rangeable storage type'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $repository = $this->createMock(ProductAttributeRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($attribute);

        $filter = new ProductAttributeValueFilter(
            $this->mockLeanDetector(returns: true),
            $repository,
            'en_US',
            $this->createMock(ManagerRegistry::class),
            $logger,
        );
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['attribute_range' => ['t_shirt_brand' => ['gte' => 'a']]],
        ]);
    }

    /**
     * `new \DateTimeImmutable('a while ago')` parses - to NOW, with a warning
     * nobody reads. A dormant-stock range built on it would ask for "before
     * now" and match everything. The bound has to be dropped, not guessed.
     */
    public function testItSkipsADateBoundPhpCannotParseCleanly(): void
    {
        $attribute = $this->createMock(ProductAttributeInterface::class);
        $attribute->method('getStorageType')->willReturn(AttributeValueInterface::STORAGE_DATE);

        $repository = $this->createMock(ProductAttributeRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($attribute);

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $filter = new ProductAttributeValueFilter(
            $this->mockLeanDetector(returns: true),
            $repository,
            'en_US',
            $this->createMock(ManagerRegistry::class),
        );
        $filter->apply($qb, $this->mockQng(), Product::class, null, [
            'filters' => ['attribute_range' => ['release_date' => ['gte' => 'a while ago']]],
        ]);
    }

    public function testItSkipsAttributeWithNullStorageType(): void
    {
        $attribute = $this->createMock(ProductAttributeInterface::class);
        $attribute->method('getStorageType')->willReturn(null);

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('leftJoin');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('no storage type'));

        $repository = $this->createMock(ProductAttributeRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($attribute);

        $filter = new ProductAttributeValueFilter(
            $this->mockLeanDetector(returns: true),
            $repository,
            'en_US',
            $this->createMock(ManagerRegistry::class),
            $logger,
        );
        $filter->apply($qb, $this->mockQng(), Product::class, null, ['filters' => ['attribute' => ['weird' => 'value']]]);
    }

    public function testGetDescriptionExposesThreeQueryKeys(): void
    {
        $filter = $this->filter(returns: true);
        $description = $filter->getDescription(Product::class);

        self::assertArrayHasKey('attribute[<code>]', $description);
        self::assertArrayHasKey('attribute_present[]', $description);
        self::assertArrayHasKey('attribute_range[<code>][gte]', $description);
        self::assertArrayHasKey('attribute_range[<code>][lte]', $description);

        foreach ($description as $entry) {
            self::assertStringContainsString('Prefer: respond-for=lean', $entry['description']);
        }
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
                /** @var array<string, list<array<string, mixed>>> $joins */
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

    private function filter(bool $returns, ?ProductAttributeInterface $attribute = null): ProductAttributeValueFilter
    {
        $repository = $this->createMock(ProductAttributeRepositoryInterface::class);
        if (null !== $attribute) {
            $repository->method('findOneBy')->willReturn($attribute);
        }

        return new ProductAttributeValueFilter(
            $this->mockLeanDetector($returns),
            $repository,
            'en_US',
            $this->createMock(ManagerRegistry::class),
        );
    }

    /**
     * Build a real detector backed by a request stack that either does or does
     * not carry the Prefer header - LeanRequestDetector is final and cannot be
     * doubled, so we drive it through its real input.
     */
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

    private function mockAttribute(string $storageType): ProductAttributeInterface&MockObject
    {
        $attribute = $this->createMock(ProductAttributeInterface::class);
        $attribute->method('getStorageType')->willReturn($storageType);

        return $attribute;
    }

    private function mockQueryBuilder(): QueryBuilder&MockObject
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['o']);

        return $qb;
    }

    private function mockQng(): QueryNameGeneratorInterface&MockObject
    {
        $qng = $this->createMock(QueryNameGeneratorInterface::class);
        $counter = 0;
        $qng->method('generateJoinAlias')->willReturnCallback(static fn (string $prefix): string => $prefix . '_' . ++$counter);
        $qng->method('generateParameterName')->willReturnCallback(static fn (string $prefix): string => $prefix . '_' . ++$counter);

        return $qng;
    }
}
