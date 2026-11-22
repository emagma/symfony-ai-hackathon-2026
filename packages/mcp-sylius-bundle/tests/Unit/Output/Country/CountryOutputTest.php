<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\Country;

use Emagma\McpSyliusBundle\Output\Country\CountryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountryOutput::class)]
final class CountryOutputTest extends TestCase
{
    public function testItShapesFullPayload(): void
    {
        $shaped = CountryOutput::fromPayload([
            'code' => 'JP',
            'name' => 'Japan',
            'enabled' => true,
            'provinces' => [['code' => 'JP-TK'], ['code' => 'JP-OS']],
        ], 'fallback');

        self::assertTrue($shaped->found);
        self::assertSame('JP', $shaped->code);
        self::assertSame('Japan', $shaped->name);
        self::assertTrue($shaped->enabled);
        self::assertSame(2, $shaped->provincesCount);
    }

    public function testItUsesTheFallbackCodeWhenCodeIsMissing(): void
    {
        $shaped = CountryOutput::fromPayload(['name' => 'Japan', 'enabled' => true, 'provinces' => []], 'JP');

        self::assertSame('JP', $shaped->code);
    }

    public function testItReturnsNullNameWhenMissing(): void
    {
        $shaped = CountryOutput::fromPayload(['code' => 'JP', 'enabled' => true, 'provinces' => []], 'JP');

        self::assertNull($shaped->name);
    }

    public function testItDefaultsEnabledToFalseWhenMissing(): void
    {
        $shaped = CountryOutput::fromPayload(['code' => 'JP', 'provinces' => []], 'JP');

        self::assertFalse($shaped->enabled);
    }

    public function testItReturnsZeroProvincesCountWhenProvincesIsEmpty(): void
    {
        $shaped = CountryOutput::fromPayload(['code' => 'JP', 'enabled' => true, 'provinces' => []], 'JP');

        self::assertSame(0, $shaped->provincesCount);
    }

    public function testItReturnsZeroProvincesCountWhenProvincesMissing(): void
    {
        $shaped = CountryOutput::fromPayload(['code' => 'JP', 'enabled' => true], 'JP');

        self::assertSame(0, $shaped->provincesCount);
    }
}
