<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\ProductOption;

use Emagma\McpSyliusBundle\Output\ProductOption\ProductOptionOutput;
use Emagma\McpSyliusBundle\Output\ProductOption\ProductOptionValueOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductOptionOutput::class)]
final class ProductOptionOutputTest extends TestCase
{
    private const string LOCALE = 'en_US';

    public function testShapeReturnsCuratedFields(): void
    {
        $option = [
            'code' => 'opt_size',
            'position' => 3,
            'values' => [
                '/api/v2/admin/product-options/opt_size/values/opt_size_s',
                '/api/v2/admin/product-options/opt_size/values/opt_size_m',
            ],
            'translations' => [
                self::LOCALE => ['name' => 'Size', 'locale' => self::LOCALE],
            ],
            'createdAt' => '2026-01-01T00:00:00+00:00',
            'updatedAt' => '2026-02-01T00:00:00+00:00',
        ];

        $result = ProductOptionOutput::fromPayload($option, self::LOCALE, 'opt_size');

        self::assertTrue($result->found);
        self::assertSame('opt_size', $result->code);
        self::assertSame('Size', $result->name);
        self::assertSame(3, $result->position);
        self::assertSame([], $result->values); // no resolvedValues passed
        self::assertSame(2, $result->valuesCount);
        self::assertSame('2026-01-01T00:00:00+00:00', $result->createdAt);
        self::assertSame('2026-02-01T00:00:00+00:00', $result->updatedAt);
    }

    public function testShapeUsesFallbackCodeWhenCodeMissing(): void
    {
        $result = ProductOptionOutput::fromPayload([], self::LOCALE, 'fallback_code');

        self::assertSame('fallback_code', $result->code);
    }

    public function testShapeForwardsResolvedValues(): void
    {
        $resolvedValues = [
            ['code' => 'opt_size_s', 'value' => 'Small'],
            ['code' => 'opt_size_m', 'value' => 'Medium'],
        ];

        $result = ProductOptionOutput::fromPayload(
            ['code' => 'opt_size', 'values' => ['/iri1', '/iri2'], 'translations' => []],
            self::LOCALE,
            'opt_size',
            $resolvedValues,
        );

        self::assertSame($resolvedValues, $result->values);
        self::assertSame(2, $result->valuesCount);
    }

    public function testShapeValueResolvesTranslatedLabel(): void
    {
        $value = [
            'code' => 'opt_size_s',
            'value' => 'S', // raw fallback
            'translations' => [
                self::LOCALE => ['value' => 'Small', 'locale' => self::LOCALE],
            ],
        ];

        $result = ProductOptionValueOutput::fromPayload($value, self::LOCALE);

        self::assertSame('opt_size_s', $result->code);
        self::assertSame('Small', $result->value); // translated, not raw
    }

    public function testShapeValueFallsBackToRawValueWhenNoTranslation(): void
    {
        $value = [
            'code' => 'opt_size_s',
            'value' => 'S',
            'translations' => [],
        ];

        $result = ProductOptionValueOutput::fromPayload($value, self::LOCALE);

        self::assertSame('S', $result->value);
    }

    public function testShapeValueReturnsNullValueWhenNeitherTranslationNorRawPresent(): void
    {
        $result = ProductOptionValueOutput::fromPayload(['code' => 'opt_size_s'], self::LOCALE);

        self::assertSame('opt_size_s', $result->code);
        self::assertNull($result->value);
    }
}
