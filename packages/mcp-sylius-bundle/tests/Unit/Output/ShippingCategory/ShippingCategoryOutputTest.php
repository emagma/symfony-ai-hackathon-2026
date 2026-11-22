<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\ShippingCategory;

use Emagma\McpSyliusBundle\Output\ShippingCategory\ShippingCategoryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShippingCategoryOutput::class)]
final class ShippingCategoryOutputTest extends TestCase
{
    public function testItShapesFullPayload(): void
    {
        $shaped = ShippingCategoryOutput::fromPayload([
            'code' => 'sc_standard',
            'name' => 'Standard Shipping',
            'description' => 'Default shipping',
        ], 'fallback');

        self::assertTrue($shaped->found);
        self::assertSame('sc_standard', $shaped->code);
        self::assertSame('Standard Shipping', $shaped->name);
        self::assertSame('Default shipping', $shaped->description);
    }

    public function testItUsesTheFallbackCodeWhenCodeIsMissing(): void
    {
        $shaped = ShippingCategoryOutput::fromPayload(['name' => 'Heavy Items', 'description' => null], 'sc_heavy');

        self::assertSame('sc_heavy', $shaped->code);
    }

    public function testItReturnsNullNameWhenMissing(): void
    {
        $shaped = ShippingCategoryOutput::fromPayload(['code' => 'sc_standard', 'description' => null], 'sc_standard');

        self::assertNull($shaped->name);
    }

    public function testItReturnsNullDescriptionWhenMissing(): void
    {
        $shaped = ShippingCategoryOutput::fromPayload(['code' => 'sc_standard', 'name' => 'Standard Shipping'], 'sc_standard');

        self::assertNull($shaped->description);
    }

    public function testItReturnsNullDescriptionWhenNotAString(): void
    {
        $shaped = ShippingCategoryOutput::fromPayload(['code' => 'sc_standard', 'name' => 'Standard', 'description' => 0], 'sc_standard');

        self::assertNull($shaped->description);
    }
}
