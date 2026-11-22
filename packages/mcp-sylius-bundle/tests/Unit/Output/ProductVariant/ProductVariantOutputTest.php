<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\ProductVariant;

use Emagma\McpSyliusBundle\Output\ProductVariant\ProductVariantOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductVariantOutput::class)]
final class ProductVariantOutputTest extends TestCase
{
    private const string LOCALE = 'en_US';

    public function testItShapesACompleteVariantPayload(): void
    {
        $payload = [
            'code' => 'VAR_1',
            'product' => '/api/v2/admin/products/PROD_1',
            'enabled' => true,
            'position' => 2,
            'tracked' => true,
            'onHand' => 10,
            'onHold' => 1,
            'weight' => 0.5,
            'width' => 10.0,
            'height' => 5.0,
            'depth' => 3.0,
            'shippingRequired' => true,
            'taxCategory' => '/api/v2/admin/tax-categories/clothing',
            'shippingCategory' => '/api/v2/admin/shipping-categories/default',
            'createdAt' => '2026-01-01T00:00:00+00:00',
            'updatedAt' => '2026-02-01T00:00:00+00:00',
            'translations' => [
                self::LOCALE => ['name' => 'Red Variant', 'locale' => self::LOCALE],
            ],
            'optionValues' => [
                '/api/v2/admin/product-options/opt_color/values/opt_color_red',
                '/api/v2/admin/product-options/opt_size/values/opt_size_m',
            ],
            'channelPricings' => [
                'FASHION_WEB' => ['channelCode' => 'FASHION_WEB', 'price' => 1999, 'originalPrice' => 2499],
                'MOBILE_WEB' => ['channelCode' => 'MOBILE_WEB', 'price' => 1799, 'originalPrice' => null],
            ],
        ];

        $shaped = ProductVariantOutput::fromPayload($payload, self::LOCALE, 'fallback');

        self::assertTrue($shaped->found);
        self::assertSame('VAR_1', $shaped->code);
        self::assertSame('PROD_1', $shaped->productCode);
        self::assertSame('Red Variant', $shaped->name);
        self::assertTrue($shaped->enabled);
        self::assertSame(2, $shaped->position);
        self::assertTrue($shaped->tracked);
        self::assertSame(10, $shaped->onHand);
        self::assertSame(1, $shaped->onHold);
        self::assertSame(0.5, $shaped->weight);
        self::assertSame(10.0, $shaped->width);
        self::assertSame(5.0, $shaped->height);
        self::assertSame(3.0, $shaped->depth);
        self::assertTrue($shaped->shippingRequired);
        self::assertSame('clothing', $shaped->taxCategoryCode);
        self::assertSame('default', $shaped->shippingCategoryCode);
        self::assertSame('2026-01-01T00:00:00+00:00', $shaped->createdAt);
        self::assertSame('2026-02-01T00:00:00+00:00', $shaped->updatedAt);
        self::assertSame(['opt_color_red', 'opt_size_m'], $shaped->optionValueCodes);
        self::assertSame(['FASHION_WEB' => 1999, 'MOBILE_WEB' => 1799], $shaped->pricing);
        self::assertSame(['FASHION_WEB' => 2499], $shaped->originalPricing);
    }

    public function testItUsesFallbackCodeWhenPayloadHasNoCode(): void
    {
        $shaped = ProductVariantOutput::fromPayload([], self::LOCALE, 'FALLBACK');

        self::assertSame('FALLBACK', $shaped->code);
    }

    public function testItReturnsEmptyOptionValueCodesWhenNonePresent(): void
    {
        $shaped = ProductVariantOutput::fromPayload(['code' => 'V', 'optionValues' => []], self::LOCALE, 'V');

        self::assertSame([], $shaped->optionValueCodes);
    }

    public function testItReturnsEmptyPricingWhenChannelPricingsAbsent(): void
    {
        $shaped = ProductVariantOutput::fromPayload(['code' => 'V'], self::LOCALE, 'V');

        self::assertSame([], $shaped->pricing);
    }

    public function testItFallsBackToFirstTranslationWhenLocaleNotFound(): void
    {
        $payload = [
            'code' => 'V',
            'translations' => [
                'fr_FR' => ['name' => 'Variante Rouge'],
            ],
        ];

        $shaped = ProductVariantOutput::fromPayload($payload, self::LOCALE, 'V');

        self::assertSame('Variante Rouge', $shaped->name);
    }

    public function testItReturnsNullNameWhenNoTranslationsPresent(): void
    {
        $shaped = ProductVariantOutput::fromPayload(['code' => 'V', 'translations' => []], self::LOCALE, 'V');

        self::assertNull($shaped->name);
    }

    public function testItExtractsProductCodeFromIri(): void
    {
        $shaped = ProductVariantOutput::fromPayload(
            ['code' => 'V', 'product' => '/api/v2/admin/products/MY_PRODUCT'],
            self::LOCALE,
            'V',
        );

        self::assertSame('MY_PRODUCT', $shaped->productCode);
    }
}
