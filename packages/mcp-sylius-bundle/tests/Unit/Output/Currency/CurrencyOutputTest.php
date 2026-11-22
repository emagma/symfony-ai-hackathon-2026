<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\Currency;

use Emagma\McpSyliusBundle\Output\Currency\CurrencyOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CurrencyOutput::class)]
final class CurrencyOutputTest extends TestCase
{
    public function testItShapesFullPayload(): void
    {
        $shaped = CurrencyOutput::fromPayload(['code' => 'JPY', 'name' => 'Japanese Yen'], 'fallback');

        self::assertTrue($shaped->found);
        self::assertSame('JPY', $shaped->code);
        self::assertSame('Japanese Yen', $shaped->name);
    }

    public function testItUsesTheFallbackCodeWhenCodeIsMissing(): void
    {
        $shaped = CurrencyOutput::fromPayload(['name' => 'Euro'], 'EUR');

        self::assertSame('EUR', $shaped->code);
    }

    public function testItReturnsNullNameWhenNameIsMissing(): void
    {
        $shaped = CurrencyOutput::fromPayload(['code' => 'USD'], 'USD');

        self::assertNull($shaped->name);
    }

    public function testItReturnsNullNameWhenNameIsNotAString(): void
    {
        $shaped = CurrencyOutput::fromPayload(['code' => 'USD', 'name' => 42], 'USD');

        self::assertNull($shaped->name);
    }
}
