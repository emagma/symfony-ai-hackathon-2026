<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\ProductAttribute;

use Emagma\McpSyliusBundle\Output\ProductAttribute\ProductAttributeOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductAttributeOutput::class)]
final class ProductAttributeOutputTest extends TestCase
{
    private const string LOCALE = 'en_US';

    public function testItShapesATextAttributePayload(): void
    {
        $payload = [
            'code' => 'attr_brand',
            'type' => 'text',
            'storageType' => 'text',
            'translatable' => true,
            'position' => 5,
            'translations' => [
                self::LOCALE => ['name' => 'Brand', 'locale' => self::LOCALE],
            ],
        ];

        $shaped = ProductAttributeOutput::fromPayload($payload, self::LOCALE, 'fallback');

        self::assertTrue($shaped->found);
        self::assertSame('attr_brand', $shaped->code);
        self::assertSame('Brand', $shaped->name);
        self::assertSame('text', $shaped->type);
        self::assertSame('text', $shaped->storageType);
        self::assertTrue($shaped->translatable);
        self::assertSame(5, $shaped->position);
        self::assertNull($shaped->choices);
        self::assertSame(0, $shaped->choicesCount);
    }

    public function testItShapesASelectAttributeWithChoices(): void
    {
        $payload = [
            'code' => 'attr_size',
            'type' => 'select',
            'storageType' => 'json',
            'translatable' => false,
            'position' => 1,
            'translations' => [
                self::LOCALE => ['name' => 'Size'],
            ],
            'configuration' => [
                'multiple' => false,
                'choices' => [
                    's' => [self::LOCALE => 'Small'],
                    'm' => [self::LOCALE => 'Medium'],
                    'l' => [self::LOCALE => 'Large'],
                ],
            ],
        ];

        $shaped = ProductAttributeOutput::fromPayload($payload, self::LOCALE, 'fallback');

        self::assertSame('select', $shaped->type);
        self::assertIsArray($shaped->choices);
        self::assertArrayHasKey('s', $shaped->choices);
        self::assertSame(3, $shaped->choicesCount);
    }

    public function testItReturnsNullChoicesForNonSelectType(): void
    {
        $payload = [
            'code' => 'attr_weight',
            'type' => 'float',
            'configuration' => [],
        ];

        $shaped = ProductAttributeOutput::fromPayload($payload, self::LOCALE, 'fallback');

        self::assertNull($shaped->choices);
        self::assertSame(0, $shaped->choicesCount);
    }

    public function testItUsesFallbackCodeWhenPayloadHasNoCode(): void
    {
        $shaped = ProductAttributeOutput::fromPayload([], self::LOCALE, 'FALLBACK');

        self::assertSame('FALLBACK', $shaped->code);
    }

    public function testItFallsBackToFirstTranslationWhenLocaleNotFound(): void
    {
        $payload = [
            'code' => 'attr_x',
            'type' => 'text',
            'translations' => [
                'fr_FR' => ['name' => 'Marque'],
            ],
        ];

        $shaped = ProductAttributeOutput::fromPayload($payload, self::LOCALE, 'attr_x');

        self::assertSame('Marque', $shaped->name);
    }

    public function testItReturnsNullNameWhenNoTranslationsPresent(): void
    {
        $shaped = ProductAttributeOutput::fromPayload(
            ['code' => 'attr_x', 'type' => 'text', 'translations' => []],
            self::LOCALE,
            'attr_x',
        );

        self::assertNull($shaped->name);
    }
}
