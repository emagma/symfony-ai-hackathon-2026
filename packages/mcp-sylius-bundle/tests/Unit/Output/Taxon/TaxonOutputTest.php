<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\Taxon;

use Emagma\McpSyliusBundle\Output\Taxon\TaxonOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxonOutput::class)]
final class TaxonOutputTest extends TestCase
{
    private const string LOCALE = 'en_US';

    public function testShapeReturnsCuratedFields(): void
    {
        $taxon = [
            'code' => 'cats',
            'enabled' => true,
            'position' => 5,
            'level' => 1,
            'parent' => '/api/v2/admin/taxons/animals',
            'children' => [
                '/api/v2/admin/taxons/kittens',
                '/api/v2/admin/taxons/seniors',
            ],
            'images' => [['id' => 1], ['id' => 2]],
            'translations' => [
                self::LOCALE => [
                    'name' => 'Cats',
                    'slug' => 'cats',
                    'description' => 'All about cats.',
                    'locale' => self::LOCALE,
                ],
            ],
        ];

        $result = TaxonOutput::fromPayload($taxon, self::LOCALE, 'cats');

        self::assertTrue($result->found);
        self::assertSame('cats', $result->code);
        self::assertSame('Cats', $result->name);
        self::assertSame('cats', $result->slug);
        self::assertSame('All about cats.', $result->description);
        self::assertTrue($result->enabled);
        self::assertSame(5, $result->position);
        self::assertSame(1, $result->level);
        self::assertSame('animals', $result->parentCode);
        self::assertSame(['kittens', 'seniors'], $result->childrenCodes);
        self::assertSame(2, $result->childrenCount);
        self::assertSame(2, $result->imagesCount);
    }

    public function testShapeUsesFallbackCodeWhenCodeMissing(): void
    {
        $result = TaxonOutput::fromPayload([], self::LOCALE, 'fallback');

        self::assertSame('fallback', $result->code);
    }

    public function testShapeReturnsNullParentCodeWhenNoParent(): void
    {
        $result = TaxonOutput::fromPayload(['code' => 'root', 'enabled' => true], self::LOCALE, 'root');

        self::assertNull($result->parentCode);
        self::assertSame([], $result->childrenCodes);
        self::assertSame(0, $result->childrenCount);
        self::assertSame(0, $result->imagesCount);
    }

    public function testShapeFallsBackToFirstAvailableTranslation(): void
    {
        $taxon = [
            'code' => 'cats',
            'enabled' => false,
            'translations' => [
                'de_DE' => ['name' => 'Katzen', 'slug' => 'katzen'],
            ],
        ];

        $result = TaxonOutput::fromPayload($taxon, self::LOCALE, 'cats');

        self::assertSame('Katzen', $result->name);
        self::assertSame('katzen', $result->slug);
    }
}
