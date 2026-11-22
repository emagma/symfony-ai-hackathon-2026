<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\ShippingMethod;

use Emagma\McpSyliusBundle\Output\ShippingMethod\ShippingMethodOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShippingMethodOutput::class)]
final class ShippingMethodOutputTest extends TestCase
{
    private const string LOCALE = 'en_US';

    public function testItExposesTheArchivedTimestamp(): void
    {
        $shaped = ShippingMethodOutput::fromPayload(
            ['code' => 'old_carrier', 'archivedAt' => '2026-09-07T10:00:00+00:00'],
            self::LOCALE,
            'old_carrier',
        );

        self::assertSame('2026-09-07T10:00:00+00:00', $shaped->archivedAt);
    }

    public function testArchivedAtIsNullForAnActiveMethod(): void
    {
        $shaped = ShippingMethodOutput::fromPayload(['code' => 'ups_ground'], self::LOCALE, 'ups_ground');

        self::assertNull($shaped->archivedAt);
    }

    public function testItShapesAFullPayload(): void
    {
        $raw = [
            'code' => 'ups_ground',
            'enabled' => true,
            'position' => 2,
            'zone' => '/api/v2/admin/zones/US',
            'channels' => ['/api/v2/admin/channels/FASHION_WEB', '/api/v2/admin/channels/MOBILE'],
            'shippingChargesCalculator' => 'flat_rate',
            'shippingChargesCalculatorConfiguration' => [
                'FASHION_WEB' => ['amount' => 500],
            ],
            'taxCategory' => '/api/v2/admin/tax-categories/default',
            'category' => '/api/v2/admin/shipping-categories/standard',
            'minDeliveryTimeDays' => 2,
            'maxDeliveryTimeDays' => 5,
            'translations' => [
                self::LOCALE => ['name' => 'UPS Ground', 'description' => 'Standard ground shipping'],
            ],
        ];

        $shaped = ShippingMethodOutput::fromPayload($raw, self::LOCALE, 'ups_ground');

        self::assertTrue($shaped->found);
        self::assertSame('ups_ground', $shaped->code);
        self::assertSame('UPS Ground', $shaped->name);
        self::assertSame('Standard ground shipping', $shaped->description);
        self::assertTrue($shaped->enabled);
        self::assertSame(2, $shaped->position);
        self::assertSame('US', $shaped->zoneCode);
        self::assertSame(['FASHION_WEB', 'MOBILE'], $shaped->channelCodes);
        self::assertSame('flat_rate', $shaped->calculator);
        self::assertSame(['FASHION_WEB' => ['amount' => 500]], $shaped->chargesPerChannel);
        self::assertSame('default', $shaped->taxCategoryCode);
        self::assertSame('standard', $shaped->categoryCode);
        self::assertSame(2, $shaped->minDeliveryTimeDays);
        self::assertSame(5, $shaped->maxDeliveryTimeDays);
    }

    public function testItFallsBackToWriteSideCalculatorKey(): void
    {
        $raw = [
            'calculator' => 'per_unit_rate',
            'translations' => [],
            'channels' => [],
        ];

        $shaped = ShippingMethodOutput::fromPayload($raw, self::LOCALE, 'fallback');

        self::assertSame('per_unit_rate', $shaped->calculator);
    }

    public function testItFallsBackToFallbackCodeWhenCodeMissing(): void
    {
        $shaped = ShippingMethodOutput::fromPayload([], self::LOCALE, 'my_fallback');

        self::assertSame('my_fallback', $shaped->code);
    }

    public function testItReturnsNullNameWhenTranslationMissing(): void
    {
        $shaped = ShippingMethodOutput::fromPayload(['translations' => []], self::LOCALE, 'sm');

        self::assertNull($shaped->name);
        self::assertNull($shaped->description);
    }

    public function testItUsesAlternativeLocaleTranslation(): void
    {
        $raw = [
            'translations' => [
                'fr_FR' => ['name' => 'Livraison standard', 'description' => 'desc fr'],
            ],
            'channels' => [],
        ];

        $shaped = ShippingMethodOutput::fromPayload($raw, 'fr_FR', 'sm');

        self::assertSame('Livraison standard', $shaped->name);
        self::assertSame('desc fr', $shaped->description);
    }
}
