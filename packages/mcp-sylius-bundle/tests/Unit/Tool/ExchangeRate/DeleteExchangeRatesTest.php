<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ExchangeRate;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ExchangeRate\DeleteExchangeRates;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DeleteExchangeRates::class)]
final class DeleteExchangeRatesTest extends McpToolTestCase
{
    public function testItDeletesByIdAndReportsTheRest(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn([
            '1' => ['ok' => true, 'body' => ['id' => 1, 'sourceCurrency' => '/api/v2/admin/currencies/EUR', 'targetCurrency' => '/api/v2/admin/currencies/GBP']],
            '9' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::once())->method('deleteMany')
            ->with(['1' => 'exchange-rates/1'])
            ->willReturn(['1' => ['ok' => true]]);

        $result = $this->call(new DeleteExchangeRates($client, $this->audit()), ids: [1, 9], confirm: true);

        self::assertSame(['ok' => true, 'action' => 'deleted'], $result['results']['1']);
        self::assertSame('not_found', $result['results']['9']['reason']);
    }
}
