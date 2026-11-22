<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\Product;

use Emagma\McpSyliusBundle\Output\Product\ProductOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductOutput::class)]
final class ProductOutputTest extends TestCase
{
    private const string LOCALE = 'en_US';

    public function testTheLocalesThatReallyHaveATranslationAreListed(): void
    {
        $product = ['code' => 'cap', 'translations' => [
            'en_US' => ['name' => 'Cap'],
            'de_DE' => ['name' => 'Mütze'],
            'fr_FR' => ['name' => ''],
        ]];

        $result = ProductOutput::fromPayload($product, 'it_IT', 'cap');

        // it_IT has none: its texts fall back to another translation, the list tells it apart.
        self::assertSame('Cap', $result->name);
        self::assertSame(['en_US', 'de_DE'], $result->translatedLocales);
    }

    public function testItShapesAFullProductPayload(): void
    {
        $product = [
            'code' => 'shirt_001',
            'enabled' => true,
            'averageRating' => 4.5,
            'variantSelectionMethod' => 'match',
            'createdAt' => '2026-01-01T00:00:00+00:00',
            'updatedAt' => '2026-02-02T00:00:00+00:00',
            'mainTaxon' => '/api/v2/admin/taxons/mens_t_shirts',
            'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
            'options' => ['/api/v2/admin/product-options/t_shirt_size'],
            'productTaxons' => ['/api/v2/admin/product-taxons/1', '/api/v2/admin/product-taxons/2'],
            'images' => [['path' => 'foo.webp']],
            'attributes' => [['value' => 'a'], ['value' => 'b']],
            'variants' => ['/api/v2/admin/product-variants/V-1'],
            'translations' => [
                self::LOCALE => [
                    'name' => 'Apollo T-Shirt',
                    'slug' => 'apollo-t-shirt',
                    'description' => 'A nice tee.',
                    'shortDescription' => 'Tee.',
                    'metaKeywords' => 'tee, shirt',
                    'metaDescription' => 'The Apollo tee.',
                ],
            ],
        ];

        $result = ProductOutput::fromPayload($product, self::LOCALE, 'shirt_001');

        self::assertTrue($result->found);
        self::assertSame('shirt_001', $result->code);
        self::assertSame('Apollo T-Shirt', $result->name);
        self::assertSame('apollo-t-shirt', $result->slug);
        self::assertSame('A nice tee.', $result->description);
        self::assertSame('Tee.', $result->shortDescription);
        self::assertSame('tee, shirt', $result->metaKeywords);
        self::assertSame('The Apollo tee.', $result->metaDescription);
        self::assertTrue($result->enabled);
        self::assertSame(4.5, $result->averageRating);
        self::assertSame('match', $result->variantSelectionMethod);
        self::assertSame('2026-01-01T00:00:00+00:00', $result->createdAt);
        self::assertSame('2026-02-02T00:00:00+00:00', $result->updatedAt);
        self::assertSame('mens_t_shirts', $result->mainTaxonCode);
        self::assertSame(['FASHION_WEB'], $result->channelCodes);
        self::assertSame(['t_shirt_size'], $result->optionCodes);
        self::assertSame(2, $result->taxonsCount);
        self::assertSame(1, $result->imagesCount);
        self::assertSame(2, $result->attributesCount);
        self::assertSame(1, $result->variantsCount);
    }

    public function testItFallsBackToFirstAvailableTranslationWhenLocaleMissing(): void
    {
        $product = [
            'code' => 'shirt_001',
            'enabled' => false,
            'translations' => [
                'fr_FR' => ['name' => 'Chemise', 'slug' => 'chemise'],
            ],
        ];

        $result = ProductOutput::fromPayload($product, self::LOCALE, 'shirt_001');

        self::assertSame('Chemise', $result->name);
        self::assertSame('chemise', $result->slug);
    }

    public function testItHandlesMissingTranslationsGracefully(): void
    {
        $product = [
            'code' => 'empty_001',
            'enabled' => false,
            'translations' => [],
        ];

        $result = ProductOutput::fromPayload($product, self::LOCALE, 'empty_001');

        self::assertSame('empty_001', $result->code);
        self::assertNull($result->name);
        self::assertNull($result->slug);
        self::assertNull($result->description);
        self::assertFalse($result->enabled);
        self::assertNull($result->mainTaxonCode);
        self::assertSame([], $result->channelCodes);
        self::assertSame([], $result->optionCodes);
    }

    public function testItUsesFallbackCodeWhenProductCodeMissing(): void
    {
        $product = [
            'enabled' => true,
            'translations' => [],
        ];

        $result = ProductOutput::fromPayload($product, self::LOCALE, 'FALLBACK');

        self::assertSame('FALLBACK', $result->code);
    }

    public function testItCountsZeroForMissingCollections(): void
    {
        $product = [
            'code' => 'p',
            'enabled' => true,
            'translations' => [],
        ];

        $result = ProductOutput::fromPayload($product, self::LOCALE, 'p');

        self::assertSame(0, $result->taxonsCount);
        self::assertSame(0, $result->imagesCount);
        self::assertSame(0, $result->attributesCount);
        self::assertSame(0, $result->variantsCount);
    }
}
