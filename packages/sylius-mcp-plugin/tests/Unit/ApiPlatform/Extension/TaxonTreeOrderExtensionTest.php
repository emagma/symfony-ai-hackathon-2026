<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Extension;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use Emagma\SyliusMcpPlugin\ApiPlatform\Extension\TaxonTreeOrderExtension;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\Taxon;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(TaxonTreeOrderExtension::class)]
final class TaxonTreeOrderExtensionTest extends TestCase
{
    public function testItOrdersALeanTaxonCollectionByRootThenNestedSetLeft(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['o']);
        $orderBy = [];
        $qb->method('addOrderBy')->willReturnCallback(
            static function (string $sort, string $order) use (&$orderBy, $qb): QueryBuilder {
                $orderBy[] = [$sort, $order];

                return $qb;
            },
        );

        $this->extension(lean: true)->applyToCollection($qb, $this->createMock(QueryNameGeneratorInterface::class), Taxon::class);

        // `left` restarts per tree root: the root must come first.
        self::assertSame([['o.root', 'ASC'], ['o.left', 'ASC']], $orderBy);
    }

    public function testItLeavesANonLeanRequestAlone(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::never())->method('addOrderBy');

        $this->extension(lean: false)->applyToCollection($qb, $this->createMock(QueryNameGeneratorInterface::class), Taxon::class);
    }

    public function testItLeavesOtherResourcesAlone(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::never())->method('addOrderBy');

        $this->extension(lean: true)->applyToCollection($qb, $this->createMock(QueryNameGeneratorInterface::class), Product::class);
    }

    private function extension(bool $lean): TaxonTreeOrderExtension
    {
        $request = new Request();
        if ($lean) {
            $request->headers->set('Prefer', 'respond-for=lean');
        }
        $stack = new RequestStack();
        $stack->push($request);

        return new TaxonTreeOrderExtension(new LeanRequestDetector($stack));
    }
}
