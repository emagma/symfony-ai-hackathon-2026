<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\ExchangeRate;

use Emagma\McpSyliusBundle\Output\ExchangeRate\ExchangeRateOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeRateOutput::class)]
final class ExchangeRateOutputTest extends TestCase
{
    public function testItShapesAFullPayload(): void
    {
        $raw = [
            'id' => 42,
            'ratio' => 1.23,
            'sourceCurrency' => '/api/v2/admin/currencies/EUR',
            'targetCurrency' => '/api/v2/admin/currencies/GBP',
        ];

        $shaped = ExchangeRateOutput::fromPayload($raw, 99);

        self::assertTrue($shaped->found);
        self::assertSame(42, $shaped->id);
        self::assertSame(1.23, $shaped->ratio);
        self::assertSame('EUR', $shaped->sourceCurrencyCode);
        self::assertSame('GBP', $shaped->targetCurrencyCode);
    }

    public function testItFallsBackToFallbackIdWhenIdMissing(): void
    {
        $shaped = ExchangeRateOutput::fromPayload([], 99);

        self::assertSame(99, $shaped->id);
    }

    public function testItReturnsNullFieldsWhenRawIsMissing(): void
    {
        $shaped = ExchangeRateOutput::fromPayload(['id' => 1], 1);

        self::assertNull($shaped->ratio);
        self::assertNull($shaped->sourceCurrencyCode);
        self::assertNull($shaped->targetCurrencyCode);
    }

    public function testItCoercesIntRatioToFloat(): void
    {
        $shaped = ExchangeRateOutput::fromPayload(['id' => 1, 'ratio' => 1], 1);

        self::assertSame(1.0, $shaped->ratio);
    }
}
