<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ExchangeRate;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ExchangeRate\GetExchangeRates;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GetExchangeRates::class)]
final class GetExchangeRatesTest extends McpToolTestCase
{
    public function testItAnswersTheOnesThatExistAndNamesTheOthers(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecodedMany')
            ->with([1 => 'exchange-rates/1', 9 => 'exchange-rates/9'])
            ->willReturn([
                1 => ['ok' => true, 'body' => ['id' => 1, 'ratio' => 1.2, 'sourceCurrency' => '/api/v2/admin/currencies/EUR', 'targetCurrency' => '/api/v2/admin/currencies/GBP']],
                9 => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            ]);

        $result = $this->call(new GetExchangeRates($client), ids: [1, 9, 1, 0]);

        self::assertSame([
            'found' => [['found' => true, 'id' => 1, 'ratio' => 1.2, 'sourceCurrencyCode' => 'EUR', 'targetCurrencyCode' => 'GBP']],
            'missing' => [9],
        ], $result);
    }

    public function testTooManyIdsAreRefusedWithoutARequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new GetExchangeRates($client), ids: range(1, 51));

        self::assertSame(['found' => [], 'missing' => [], 'reason' => 'too_many_ids'], $result);
    }
}
