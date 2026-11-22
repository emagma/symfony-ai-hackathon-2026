<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ExchangeRate;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ExchangeRate\UpsertExchangeRates;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertExchangeRates::class)]
final class UpsertExchangeRatesTest extends McpToolTestCase
{
    public function testThePairIsTheKeyInEitherDirectionAndThePutGoesToTheId(): void
    {
        $eurGbp = ['@id' => '/api/v2/admin/exchange-rates/7', 'id' => 7, 'ratio' => 0.85, 'sourceCurrency' => '/api/v2/admin/currencies/EUR', 'targetCurrency' => '/api/v2/admin/currencies/GBP'];
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecoded')->with('exchange-rates', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([$eurGbp]));
        $client->expects(self::never())->method('getDecodedMany');
        $client->expects(self::once())->method('postMany')
            ->with(['USD/EUR' => ['path' => 'exchange-rates', 'body' => ['ratio' => 0.92, 'sourceCurrency' => '/api/v2/admin/currencies/USD', 'targetCurrency' => '/api/v2/admin/currencies/EUR']]])
            ->willReturn(['USD/EUR' => ['ok' => true, 'body' => ['id' => 8]]]);
        $client->expects(self::once())->method('putMany')
            ->with(['GBP/EUR' => ['path' => 'exchange-rates/7', 'body' => array_replace($eurGbp, ['ratio' => 0.9])]])
            ->willReturn(['GBP/EUR' => ['ok' => true, 'body' => $eurGbp]]);

        $result = $this->call(new UpsertExchangeRates($client, $this->audit(), $this->adapter()), items: [
            ['sourceCurrencyCode' => 'gbp', 'targetCurrencyCode' => 'eur', 'ratio' => 0.9],
            ['sourceCurrencyCode' => 'USD', 'targetCurrencyCode' => 'EUR', 'ratio' => 0.92],
            ['sourceCurrencyCode' => 'CHF', 'targetCurrencyCode' => 'EUR'],
        ], confirm: true);

        self::assertSame(['GBP/EUR', 'USD/EUR', 'CHF/EUR'], array_keys($result['results']));
        self::assertSame(['ratio' => ['before' => 0.85, 'after' => 0.9]], $result['results']['GBP/EUR']['diff']);
        self::assertSame('created', $result['results']['USD/EUR']['action']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['ratio']], $result['results']['CHF/EUR']);
    }

    public function testTheSameRatioIsUnchangedAndAMissingPairRefusesTheBatch(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['id' => 7, 'ratio' => 0.85, 'sourceCurrency' => '/api/v2/admin/currencies/EUR', 'targetCurrency' => '/api/v2/admin/currencies/GBP']]));
        $client->expects(self::never())->method('putMany');

        $tool = new UpsertExchangeRates($client, $this->audit(), $this->adapter());
        self::assertSame('unchanged', $this->call($tool, items: [['sourceCurrencyCode' => 'EUR', 'targetCurrencyCode' => 'GBP', 'ratio' => 0.85]])['results']['EUR/GBP']['action']);
        self::assertSame('items_invalid', $this->call($tool, items: [['sourceCurrencyCode' => 'EUR']])['reason']);
    }
}
