<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Channel;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Channel\UpsertChannels;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertChannels::class)]
final class UpsertChannelsTest extends McpToolTestCase
{
    public function testACreationCompletesItsListsAndAnUpdateReplacesThem(): void
    {
        $web = ['code' => 'WEB', 'name' => 'Web', 'hostname' => 'shop.test', 'enabled' => true, 'baseCurrency' => '/api/v2/admin/currencies/EUR',
            'defaultLocale' => '/api/v2/admin/locales/en_US', 'currencies' => ['/api/v2/admin/currencies/EUR'], 'locales' => ['/api/v2/admin/locales/en_US'], 'countries' => []];
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn([
            'WEB' => ['ok' => true, 'body' => $web],
            'B2B' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'HALF' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['B2B' => ['path' => 'channels', 'body' => [
                'code' => 'B2B', 'name' => 'B2B', 'hostname' => 'b2b.test',
                'baseCurrency' => '/api/v2/admin/currencies/EUR', 'defaultLocale' => '/api/v2/admin/locales/fr_FR',
                'currencies' => ['/api/v2/admin/currencies/USD', '/api/v2/admin/currencies/EUR'],
                'locales' => ['/api/v2/admin/locales/fr_FR'],
                'taxCalculationStrategy' => 'order_items_based', 'enabled' => false,
                'menuTaxon' => '/api/v2/admin/taxons/menu',
            ]]])
            ->willReturn(['B2B' => ['ok' => true, 'body' => ['code' => 'B2B']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['WEB' => ['path' => 'channels/WEB', 'body' => array_replace($web, [
                'name' => 'Web shop', 'defaultTaxZone' => '/api/v2/admin/zones/EU',
                'currencies' => ['/api/v2/admin/currencies/EUR', '/api/v2/admin/currencies/GBP'],
            ])]])
            ->willReturn(['WEB' => ['ok' => true, 'body' => $web]]);

        $result = $this->call(new UpsertChannels($client, $this->audit(), $this->adapter()), items: [
            ['code' => 'WEB', 'name' => 'Web shop', 'defaultTaxZoneCode' => 'EU', 'currencyCodes' => ['EUR', 'GBP'], 'localeCodes' => ['en_US'], 'baseCurrencyCode' => 'USD'],
            ['code' => 'B2B', 'name' => 'B2B', 'hostname' => 'b2b.test', 'baseCurrencyCode' => 'EUR', 'defaultLocaleCode' => 'fr_FR', 'currencyCodes' => ['USD'], 'localeCodes' => [], 'enabled' => false, 'menuTaxonCode' => 'menu'],
            ['code' => 'HALF', 'name' => 'Half'],
        ], confirm: true);

        self::assertSame(['name', 'defaultTaxZone', 'currencies'], array_keys($result['results']['WEB']['diff']), 'baseCurrencyCode is ignored on an update, same locales are not a change');
        self::assertSame(['EUR', 'USD'], $result['results']['B2B']['diff']['currencies']['after'] === ['USD', 'EUR'] ? ['EUR', 'USD'] : $result['results']['B2B']['diff']['currencies']['after']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['hostname', 'baseCurrencyCode', 'defaultLocaleCode', 'currencyCodes', 'localeCodes']], $result['results']['HALF']);
    }
}
