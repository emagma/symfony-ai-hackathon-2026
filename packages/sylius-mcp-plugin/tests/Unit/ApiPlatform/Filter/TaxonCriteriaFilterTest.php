<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\TaxonCriteriaFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Taxonomy\Model\TaxonTranslation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(TaxonCriteriaFilter::class)]
final class TaxonCriteriaFilterTest extends OrderFilterTestCase
{
    public function testItIsANoOpOutsideLeanMode(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');
        $qb->expects(self::never())->method('innerJoin');

        $this->filter(lean: false)->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['name' => 'cat', 'code' => 'cat', 'parentCode' => 'animals', 'rootOnly' => 'true', 'enabled' => 'true', 'subtreeOf' => 'animals'],
        ]);
    }

    public function testItIgnoresUnrelatedKeys(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['page' => '1', 'itemsPerPage' => '30', 'slug' => 'cats'],
        ]);
    }

    public function testNameMatchesAnyTranslationThroughAnExistsSubquery(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['name' => ' Cat '],
        ]);

        self::assertCount(1, $captured['wheres']);
        self::assertStringStartsWith('EXISTS (SELECT ', $captured['wheres'][0]);
        self::assertStringContainsString('FROM ' . TaxonTranslation::class . ' ', $captured['wheres'][0]);
        self::assertStringContainsString('.translatable = o', $captured['wheres'][0]);
        self::assertStringContainsString('LOWER(taxon_tr_1.name) LIKE :taxon_name_2', $captured['wheres'][0]);
        self::assertStringNotContainsString('.locale', $captured['wheres'][0]);
        self::assertSame(['taxon_name_2' => '%cat%'], $captured['params']);
        self::assertSame([], $captured['joins'], 'a one-to-many must not be joined: it would duplicate rows');
    }

    public function testNameIsScopedToTheLocaleOfThePreferHeader(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter(locale: 'fr_FR')->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['name' => 'chat'],
        ]);

        self::assertStringContainsString('AND taxon_tr_1.locale = :taxon_locale_3)', $captured['wheres'][0]);
        self::assertSame(['taxon_name_2' => '%chat%', 'taxon_locale_3' => 'fr_FR'], $captured['params']);
    }

    public function testNameEscapesLikeWildcards(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['name' => '100%_x'],
        ]);

        self::assertSame(['%100\%\_x%'], array_values($captured['params']));
    }

    public function testCodeIsAPartialCaseInsensitiveMatch(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['code' => 'T-Sh'],
        ]);

        self::assertSame(['LOWER(o.code) LIKE :taxon_code_1'], $captured['wheres']);
        self::assertSame(['taxon_code_1' => '%t-sh%'], $captured['params']);
    }

    public function testParentCodeJoinsTheParentAndMatchesItsCodeExactly(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['parentCode' => ' animals '],
        ]);

        self::assertCount(1, $captured['joins']);
        self::assertSame('inner', $captured['joins'][0]['kind']);
        self::assertSame('o.parent', $captured['joins'][0]['target']);
        self::assertSame(['taxon_parent_1.code = :taxon_parent_code_2'], $captured['wheres']);
        self::assertSame(['taxon_parent_code_2' => 'animals'], $captured['params']);
    }

    public function testRootOnlyTrueKeepsTaxonsWithoutParent(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['rootOnly' => 'true'],
        ]);

        self::assertSame(['o.parent IS NULL'], $captured['wheres']);
        self::assertSame([], $captured['params']);
    }

    public function testRootOnlyFalseIsANoOp(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['rootOnly' => 'false'],
        ]);
    }

    public static function booleanProvider(): array
    {
        return [
            'real true' => [true, true],
            'real false' => [false, false],
            'string true' => ['true', true],
            'string false' => ['false', false],
            'one' => ['1', true],
            'zero' => ['0', false],
        ];
    }

    #[DataProvider('booleanProvider')]
    public function testEnabledIsParsedFromQueryStrings(mixed $input, bool $expected): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['enabled' => $input],
        ]);

        self::assertSame(['o.enabled = :taxon_enabled_1'], $captured['wheres']);
        self::assertSame(['taxon_enabled_1' => $expected], $captured['params']);
    }

    public function testSubtreeOfJoinsTheAncestorAndBoundsTheNestedSetInterval(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['subtreeOf' => 'animals'],
        ]);

        self::assertCount(1, $captured['joins']);
        self::assertSame('inner', $captured['joins'][0]['kind']);
        self::assertSame(Taxon::class, $captured['joins'][0]['target'], 'the ancestor is joined as a free entity, by code');
        self::assertSame('taxon_ancestor_1', $captured['joins'][0]['alias']);
        self::assertSame('taxon_ancestor_1.code = :taxon_subtree_of_2', $captured['joins'][0]['condition']);
        self::assertSame([
            'o.root = taxon_ancestor_1.root',
            'o.left >= taxon_ancestor_1.left',
            'o.right <= taxon_ancestor_1.right',
        ], $captured['wheres']);
        self::assertSame(['taxon_subtree_of_2' => 'animals'], $captured['params']);
    }

    public function testDepthWithSubtreeOfIsRelativeToTheAncestor(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['subtreeOf' => 'animals', 'depth' => '1'],
        ]);

        self::assertSame([
            'o.root = taxon_ancestor_1.root',
            'o.left >= taxon_ancestor_1.left',
            'o.right <= taxon_ancestor_1.right',
            'o.level <= taxon_ancestor_1.level + :taxon_depth_3',
        ], $captured['wheres']);
        self::assertSame(['taxon_subtree_of_2' => 'animals', 'taxon_depth_3' => 1], $captured['params']);
    }

    public function testDepthWithSubtreeOfIsBoundOnceWhateverTheKeyOrder(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['depth' => 0, 'subtreeOf' => 'animals'],
        ]);

        $levelPredicates = array_values(array_filter(
            $captured['wheres'],
            static fn (string $where): bool => str_contains($where, '.level'),
        ));
        self::assertSame(['o.level <= taxon_ancestor_1.level + :taxon_depth_3'], $levelPredicates);
        self::assertSame(0, $captured['params']['taxon_depth_3']);
    }

    public function testDepthAloneIsAnAbsoluteLevel(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => ['depth' => '2'],
        ]);

        self::assertSame(['o.level <= :taxon_depth_1'], $captured['wheres']);
        self::assertSame(['taxon_depth_1' => 2], $captured['params']);
    }

    public static function malformedProvider(): array
    {
        return [
            'blank name' => ['name', '   ', 'name filter'],
            'array name' => ['name', ['x'], 'name filter'],
            'blank code' => ['code', '', 'code filter'],
            'blank parentCode' => ['parentCode', ' ', 'parentCode filter'],
            'rootOnly maybe' => ['rootOnly', 'maybe', 'rootOnly filter'],
            'enabled maybe' => ['enabled', 'maybe', 'enabled filter'],
            'blank subtreeOf' => ['subtreeOf', '', 'subtreeOf filter'],
            'negative depth' => ['depth', '-1', 'depth filter'],
            'float depth' => ['depth', '1.5', 'depth filter'],
            'word depth' => ['depth', 'deep', 'depth filter'],
        ];
    }

    #[DataProvider('malformedProvider')]
    public function testAMalformedValueIsLoggedAndSkipped(string $key, mixed $value, string $logged): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains($logged));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');
        $qb->expects(self::never())->method('innerJoin');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Taxon::class, null, [
            'filters' => [$key => $value],
        ]);
    }

    public function testItDocumentsEveryKeyAsLeanOnly(): void
    {
        $description = $this->filter()->getDescription(Taxon::class);

        self::assertSame(['name', 'code', 'parentCode', 'rootOnly', 'enabled', 'subtreeOf', 'depth'], array_keys($description));
        foreach ($description as $key => $meta) {
            self::assertStringContainsString('Lean mode only', $meta['description'], $key);
        }
    }

    private function filter(bool $lean = true, ?string $locale = null, ?LoggerInterface $logger = null): TaxonCriteriaFilter
    {
        return new TaxonCriteriaFilter(
            $this->leanDetectorWithLocale($lean, $locale),
            $this->taxonManagerRegistry(),
            $logger ?? $this->silentLogger(),
        );
    }

    private function leanDetectorWithLocale(bool $lean, ?string $locale): LeanRequestDetector
    {
        $request = new Request();
        if ($lean) {
            $request->headers->set('Prefer', 'respond-for=lean' . (null === $locale ? '' : '; locale=' . $locale));
        }

        $stack = new RequestStack();
        $stack->push($request);

        return new LeanRequestDetector($stack);
    }

    /**
     * The `name` filter resolves the translation entity through the Doctrine
     * metadata of the resource, so the shop's overriding entities keep working.
     */
    private function taxonManagerRegistry(): ManagerRegistry&MockObject
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getAssociationTargetClass')->with('translations')->willReturn(TaxonTranslation::class);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);

        $registry = $this->managerRegistry();
        $registry->method('getManagerForClass')->willReturn($manager);

        return $registry;
    }
}
