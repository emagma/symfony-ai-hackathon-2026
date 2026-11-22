<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\TaxRate;

use Emagma\McpSyliusBundle\Output\TaxRate\TaxRateOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxRateOutput::class)]
final class TaxRateOutputTest extends TestCase
{
    public function testItShapesAFullPayload(): void
    {
        $raw = [
            'code' => 'US_GST',
            'name' => 'US GST',
            'amount' => 0.05,
            'includedInPrice' => false,
            'zone' => '/api/v2/admin/zones/US',
            'category' => '/api/v2/admin/tax-categories/clothing',
            'calculator' => 'default',
            'startDate' => '2025-01-01T00:00:00+00:00',
            'endDate' => '2026-01-01T00:00:00+00:00',
        ];

        $shaped = TaxRateOutput::fromPayload($raw, 'US_GST');

        self::assertTrue($shaped->found);
        self::assertSame('US_GST', $shaped->code);
        self::assertSame('US GST', $shaped->name);
        self::assertSame(0.05, $shaped->amount);
        self::assertFalse($shaped->includedInPrice);
        self::assertSame('US', $shaped->zoneCode);
        self::assertSame('clothing', $shaped->categoryCode);
        self::assertSame('default', $shaped->calculator);
        self::assertSame('2025-01-01T00:00:00+00:00', $shaped->startDate);
        self::assertSame('2026-01-01T00:00:00+00:00', $shaped->endDate);
    }

    public function testItFallsBackToFallbackCodeWhenCodeMissing(): void
    {
        $shaped = TaxRateOutput::fromPayload([], 'FALLBACK');

        self::assertSame('FALLBACK', $shaped->code);
    }

    public function testItReturnsNullFieldsWhenRawIsMissing(): void
    {
        $shaped = TaxRateOutput::fromPayload(['code' => 'X'], 'X');

        self::assertNull($shaped->name);
        self::assertNull($shaped->amount);
        self::assertFalse($shaped->includedInPrice);
        self::assertNull($shaped->zoneCode);
        self::assertNull($shaped->categoryCode);
        self::assertNull($shaped->calculator);
        self::assertNull($shaped->startDate);
        self::assertNull($shaped->endDate);
    }

    public function testItCoercesIntAmountToFloat(): void
    {
        $shaped = TaxRateOutput::fromPayload(['code' => 'X', 'amount' => 1], 'X');

        self::assertSame(1.0, $shaped->amount);
    }
}
