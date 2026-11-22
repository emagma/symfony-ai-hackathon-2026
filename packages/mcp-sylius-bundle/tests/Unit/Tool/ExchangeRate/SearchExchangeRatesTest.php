<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ExchangeRate;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ExchangeRate\SearchExchangeRates;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchExchangeRates::class)]
final class SearchExchangeRatesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchExchangeRates::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_exchange_rates', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllAndReturnsCuratedItems(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('exchange-rates', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([
                $this->rateMember(1, 1.23, 'EUR', 'GBP'),
                $this->rateMember(2, 1.05, 'USD', 'EUR'),
            ]));

        $result = $this->call(new SearchExchangeRates($client));

        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['items'][0]['id']);
        self::assertSame(1.23, $result['items'][0]['ratio']);
        self::assertSame('EUR', $result['items'][0]['sourceCurrencyCode']);
        self::assertSame('GBP', $result['items'][0]['targetCurrencyCode']);
    }

    public function testItFiltersExactlyBySourceCurrency(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->rateMember(1, 1.23, 'EUR', 'GBP'),
            $this->rateMember(2, 1.05, 'USD', 'EUR'),
        ]));

        $result = $this->call(new SearchExchangeRates($client), sourceCurrency: 'EUR');

        self::assertSame(1, $result['total']);
        self::assertSame(1, $result['items'][0]['id']);
    }

    public function testItFiltersExactlyByTargetCurrency(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->rateMember(1, 1.23, 'EUR', 'GBP'),
            $this->rateMember(2, 1.05, 'USD', 'EUR'),
        ]));

        $result = $this->call(new SearchExchangeRates($client), targetCurrency: 'EUR');

        self::assertSame(1, $result['total']);
        self::assertSame(2, $result['items'][0]['id']);
    }

    public function testHasMoreIsTrueWhenTotalExceedsPage(): void
    {
        $members = array_fill(0, 35, $this->rateMember(1, 1.0, 'EUR', 'GBP'));
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchExchangeRates($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(35, $result['total']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->rateMember(1, 1.0, 'EUR', 'GBP'),
        ]));

        $result = $this->call(new SearchExchangeRates($client));

        self::assertFalse($result['hasMore']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rateMember(int $id, float $ratio, string $sourceCurrencyCode, string $targetCurrencyCode): array
    {
        return [
            'id' => $id,
            'ratio' => $ratio,
            'sourceCurrency' => '/api/v2/admin/currencies/' . $sourceCurrencyCode,
            'targetCurrency' => '/api/v2/admin/currencies/' . $targetCurrencyCode,
        ];
    }
}
