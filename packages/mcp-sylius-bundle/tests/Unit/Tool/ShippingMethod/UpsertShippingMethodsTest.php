<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ShippingMethod;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ShippingMethod\UpsertShippingMethods;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertShippingMethods::class)]
final class UpsertShippingMethodsTest extends McpToolTestCase
{
    public function testACreationNeedsTheShopRequiredFieldsAndBuildsTheIrisFromCodes(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US'], ['code' => 'fr_FR']]));
        $client->method('getDecodedMany')->willReturn([
            'ups' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'dhl' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['ups' => ['path' => 'shipping-methods', 'body' => [
                'code' => 'ups',
                'zone' => '/api/v2/admin/zones/EU',
                'calculator' => 'flat_rate',
                'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
                'configuration' => ['FASHION_WEB' => ['amount' => 500]],
                'translations' => [
                    'en_US' => ['name' => 'UPS', 'description' => 'Ground', 'locale' => 'en_US'],
                    'fr_FR' => ['name' => 'UPS', 'description' => 'Ground', 'locale' => 'fr_FR'],
                ],
                'enabled' => true,
                'position' => 2,
            ]]])
            ->willReturn(['ups' => ['ok' => true, 'body' => ['code' => 'ups']]]);

        $result = $this->call(new UpsertShippingMethods($client, $this->audit(), 'en_US', $this->adapter()), items: [
            ['code' => 'ups', 'name' => 'UPS', 'description' => 'Ground', 'zoneCode' => 'EU', 'calculator' => 'flat_rate', 'channelCodes' => ['FASHION_WEB'], 'configuration' => ['FASHION_WEB' => ['amount' => 500]], 'position' => 2],
            ['code' => 'dhl', 'name' => 'DHL', 'zoneCode' => 'EU'],
        ], confirm: true);

        self::assertSame('created', $result['results']['ups']['action']);
        self::assertSame(
            ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['calculator', 'channelCodes', 'configuration']],
            $result['results']['dhl'],
        );
    }

    public function testAnUpdateFoldsTheGetSideCalculatorNamesBackAndReplacesChannelsAndConfiguration(): void
    {
        $ups = [
            'code' => 'ups',
            'zone' => '/api/v2/admin/zones/EU',
            'shippingChargesCalculator' => 'flat_rate',
            'shippingChargesCalculatorConfiguration' => ['FASHION_WEB' => ['amount' => 500]],
            'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
            'translations' => ['en_US' => ['@id' => '/t/1', 'name' => 'UPS', 'locale' => 'en_US']],
            'enabled' => true,
            'position' => 0,
        ];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn(['ups' => ['ok' => true, 'body' => $ups]]);
        $client->expects(self::once())->method('putMany')
            ->with(['ups' => ['path' => 'shipping-methods/ups', 'body' => [
                'code' => 'ups',
                'zone' => '/api/v2/admin/zones/EU',
                'channels' => ['/api/v2/admin/channels/FASHION_WEB', '/api/v2/admin/channels/B2B'],
                'translations' => ['en_US' => ['@id' => '/t/1', 'name' => 'UPS Ground', 'locale' => 'en_US']],
                'enabled' => false,
                'position' => 0,
                'calculator' => 'flat_rate',
                'configuration' => ['FASHION_WEB' => ['amount' => 700], 'B2B' => ['amount' => 0]],
            ]]])
            ->willReturn(['ups' => ['ok' => true, 'body' => $ups]]);

        $result = $this->call(new UpsertShippingMethods($client, $this->audit(), 'en_US', $this->adapter()), items: [
            ['code' => 'ups', 'name' => 'UPS Ground', 'enabled' => false, 'calculator' => 'flat_rate', 'channelCodes' => ['FASHION_WEB', 'B2B'], 'configuration' => ['FASHION_WEB' => ['amount' => 700], 'B2B' => ['amount' => 0]]],
        ], confirm: true);

        self::assertSame('updated', $result['results']['ups']['action']);
        self::assertSame(
            ['en_US.name', 'channels', 'configuration', 'enabled'],
            array_keys($result['results']['ups']['diff']),
            'the same calculator is not a change',
        );
    }

    public function testTheSameChannelsInAnotherOrderAreUnchanged(): void
    {
        $ups = ['code' => 'ups', 'zone' => '/api/v2/admin/zones/EU', 'calculator' => 'flat_rate', 'channels' => ['/api/v2/admin/channels/A', '/api/v2/admin/channels/B'], 'translations' => [], 'enabled' => true];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn(['ups' => ['ok' => true, 'body' => $ups]]);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertShippingMethods($client, $this->audit(), 'en_US', $this->adapter()), items: [['code' => 'ups', 'channelCodes' => ['B', 'A'], 'zoneCode' => 'EU']]);

        self::assertSame('unchanged', $result['results']['ups']['action']);
    }
}
