<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxRate;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxRate\UpsertTaxRates;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertTaxRates::class)]
final class UpsertTaxRatesTest extends McpToolTestCase
{
    public function testACreationSendsTheBodyTheSingleToolSentWithIrisForZoneAndCategory(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['US_GST' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404']]);
        $client->expects(self::once())->method('postMany')
            ->with(['US_GST' => ['path' => 'tax-rates', 'body' => [
                'code' => 'US_GST',
                'name' => 'US GST',
                'amount' => 0.05,
                'includedInPrice' => false,
                'calculator' => 'default',
                'zone' => '/api/v2/admin/zones/US',
                'category' => '/api/v2/admin/tax-categories/clothing',
            ]]])
            ->willReturn(['US_GST' => ['ok' => true, 'body' => ['code' => 'US_GST']]]);

        $result = $this->call(new UpsertTaxRates($client, $this->audit(), $this->adapter()), items: [
            ['code' => 'US_GST', 'name' => 'US GST', 'amount' => 0.05, 'zoneCode' => 'US', 'categoryCode' => 'clothing'],
        ], confirm: true);

        self::assertSame('created', $result['results']['US_GST']['action']);
        self::assertSame(['before' => null, 'after' => 'US'], $result['results']['US_GST']['diff']['zone']);
        self::assertSame(['before' => null, 'after' => 'default'], $result['results']['US_GST']['diff']['calculator']);
    }

    public function testAnUpdateMergesOntoTheCurrentPayloadAndDiffsTheCodesBehindTheIris(): void
    {
        $current = [
            'code' => 'US_GST', 'name' => 'US GST', 'amount' => 0.05, 'includedInPrice' => false,
            'zone' => '/api/v2/admin/zones/US', 'category' => '/api/v2/admin/tax-categories/clothing',
            'calculator' => 'default', 'startDate' => null, 'endDate' => null,
        ];
        $expected = $current;
        $expected['name'] = 'US GST Updated';
        $expected['amount'] = 0.08;
        $expected['zone'] = '/api/v2/admin/zones/US_EAST';
        $expected['category'] = '/api/v2/admin/tax-categories/electronics';

        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['US_GST' => ['ok' => true, 'body' => $current]]);
        $client->expects(self::once())->method('putMany')
            ->with(['US_GST' => ['path' => 'tax-rates/US_GST', 'body' => $expected]])
            ->willReturn(['US_GST' => ['ok' => true, 'body' => $expected]]);

        $result = $this->call(new UpsertTaxRates($client, $this->audit(), $this->adapter()), items: [
            ['code' => 'US_GST', 'name' => 'US GST Updated', 'amount' => 0.08, 'zoneCode' => 'US_EAST', 'categoryCode' => 'electronics'],
        ], confirm: true);

        self::assertSame('updated', $result['results']['US_GST']['action']);
        self::assertSame(
            ['name' => ['before' => 'US GST', 'after' => 'US GST Updated'], 'amount' => ['before' => 0.05, 'after' => 0.08], 'zone' => ['before' => 'US', 'after' => 'US_EAST'], 'category' => ['before' => 'clothing', 'after' => 'electronics']],
            $result['results']['US_GST']['diff'],
        );
    }

    public function testTheSameValuesAreUnchanged(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['US_GST' => ['ok' => true, 'body' => ['code' => 'US_GST', 'name' => 'US GST', 'amount' => 0.05, 'zone' => '/api/v2/admin/zones/US']]]);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertTaxRates($client, $this->audit(), $this->adapter()), items: [['code' => 'US_GST', 'name' => 'US GST', 'amount' => 0.05, 'zoneCode' => 'US']]);

        self::assertSame('unchanged', $result['results']['US_GST']['action']);
    }
}
