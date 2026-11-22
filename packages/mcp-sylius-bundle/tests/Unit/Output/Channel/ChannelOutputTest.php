<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\Channel;

use Emagma\McpSyliusBundle\Output\Channel\ChannelOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelOutput::class)]
final class ChannelOutputTest extends TestCase
{
    public function testItShapesAFullChannelPayload(): void
    {
        $raw = [
            'code' => 'FASHION_WEB',
            'name' => 'Fashion Web Store',
            'enabled' => true,
            'hostname' => 'fashion.example.com',
            'color' => '#ff0000',
            'contactEmail' => 'contact@fashion.example.com',
            'baseCurrency' => '/api/v2/admin/currencies/USD',
            'defaultLocale' => '/api/v2/admin/locales/en_US',
            'defaultTaxZone' => '/api/v2/admin/zones/US',
            'taxCalculationStrategy' => 'order_items_based',
            'currencies' => ['/api/v2/admin/currencies/USD', '/api/v2/admin/currencies/EUR'],
            'locales' => ['/api/v2/admin/locales/en_US', '/api/v2/admin/locales/fr_FR'],
            'countries' => ['/api/v2/admin/countries/US', '/api/v2/admin/countries/FR'],
        ];

        $shaped = ChannelOutput::fromPayload($raw, 'FASHION_WEB');

        self::assertTrue($shaped->found);
        self::assertSame('FASHION_WEB', $shaped->code);
        self::assertSame('Fashion Web Store', $shaped->name);
        self::assertTrue($shaped->enabled);
        self::assertSame('fashion.example.com', $shaped->hostname);
        self::assertSame('#ff0000', $shaped->color);
        self::assertSame('contact@fashion.example.com', $shaped->contactEmail);
        self::assertSame('USD', $shaped->baseCurrencyCode);
        self::assertSame('en_US', $shaped->defaultLocaleCode);
        self::assertSame('US', $shaped->defaultTaxZoneCode);
        self::assertSame('order_items_based', $shaped->taxCalculationStrategy);
        self::assertSame(['USD', 'EUR'], $shaped->currencyCodes);
        self::assertSame(['en_US', 'fr_FR'], $shaped->localeCodes);
        self::assertSame(['US', 'FR'], $shaped->countryCodes);
    }

    public function testItFallsBackToFallbackCodeWhenCodeMissing(): void
    {
        $shaped = ChannelOutput::fromPayload([], 'fallback');

        self::assertSame('fallback', $shaped->code);
    }

    public function testItReturnsNullsForAbsentOptionalFields(): void
    {
        $shaped = ChannelOutput::fromPayload(['code' => 'WEB'], 'WEB');

        self::assertNull($shaped->name);
        self::assertNull($shaped->hostname);
        self::assertNull($shaped->color);
        self::assertNull($shaped->contactEmail);
        self::assertNull($shaped->baseCurrencyCode);
        self::assertNull($shaped->defaultLocaleCode);
        self::assertNull($shaped->defaultTaxZoneCode);
        self::assertNull($shaped->taxCalculationStrategy);
        self::assertSame([], $shaped->currencyCodes);
        self::assertSame([], $shaped->localeCodes);
        self::assertSame([], $shaped->countryCodes);
    }
}
