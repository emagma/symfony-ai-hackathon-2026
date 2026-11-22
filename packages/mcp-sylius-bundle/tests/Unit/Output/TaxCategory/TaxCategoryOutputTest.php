<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\TaxCategory;

use Emagma\McpSyliusBundle\Output\TaxCategory\TaxCategoryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxCategoryOutput::class)]
final class TaxCategoryOutputTest extends TestCase
{
    public function testItShapesFullPayload(): void
    {
        $shaped = TaxCategoryOutput::fromPayload([
            'code' => 'tax_standard',
            'name' => 'Standard Tax',
            'description' => 'Default tax rate',
        ], 'fallback');

        self::assertTrue($shaped->found);
        self::assertSame('tax_standard', $shaped->code);
        self::assertSame('Standard Tax', $shaped->name);
        self::assertSame('Default tax rate', $shaped->description);
    }

    public function testItUsesTheFallbackCodeWhenCodeIsMissing(): void
    {
        $shaped = TaxCategoryOutput::fromPayload(['name' => 'Standard Tax', 'description' => null], 'tax_standard');

        self::assertSame('tax_standard', $shaped->code);
    }

    public function testItReturnsNullNameWhenMissing(): void
    {
        $shaped = TaxCategoryOutput::fromPayload(['code' => 'tax_standard', 'description' => null], 'tax_standard');

        self::assertNull($shaped->name);
    }

    public function testItReturnsNullDescriptionWhenMissing(): void
    {
        $shaped = TaxCategoryOutput::fromPayload(['code' => 'tax_standard', 'name' => 'Standard Tax'], 'tax_standard');

        self::assertNull($shaped->description);
    }

    public function testItReturnsNullDescriptionWhenNotAString(): void
    {
        $shaped = TaxCategoryOutput::fromPayload(['code' => 'tax_standard', 'name' => 'Standard Tax', 'description' => 42], 'tax_standard');

        self::assertNull($shaped->description);
    }
}
