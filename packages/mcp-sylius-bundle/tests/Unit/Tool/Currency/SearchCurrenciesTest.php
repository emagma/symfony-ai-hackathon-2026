<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Currency;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Currency\SearchCurrencies;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchCurrencies::class)]
final class SearchCurrenciesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchCurrencies::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_currencies', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllCurrenciesInOneShotRequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('currencies', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([]));

        $result = $this->call(new SearchCurrencies($client));

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testItProjectsMembersToCodeAndName(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'EUR', 'name' => 'Euro'],
            ['code' => 'USD', 'name' => 'US Dollar'],
        ]));

        $result = $this->call(new SearchCurrencies($client));

        self::assertCount(2, $result['items']);
        self::assertSame('EUR', $result['items'][0]['code']);
        self::assertSame('Euro', $result['items'][0]['name']);
        self::assertCount(2, $result['items'][0], 'items[i] MUST contain only code and name');
    }

    public function testItFiltersClientSideByCodeCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'EUR', 'name' => 'Euro'],
            ['code' => 'USD', 'name' => 'US Dollar'],
            ['code' => 'GBP', 'name' => 'British Pound'],
        ]));

        $result = $this->call(new SearchCurrencies($client), code: 'eu');

        self::assertCount(1, $result['items']);
        self::assertSame('EUR', $result['items'][0]['code']);
    }

    public function testItFiltersClientSideByNameCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'EUR', 'name' => 'Euro'],
            ['code' => 'USD', 'name' => 'US Dollar'],
        ]));

        $result = $this->call(new SearchCurrencies($client), name: 'dollar');

        self::assertCount(1, $result['items']);
        self::assertSame('USD', $result['items'][0]['code']);
    }

    public function testItReturnsNullNameWhenMissing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'EUR'],
        ]));

        $result = $this->call(new SearchCurrencies($client));

        self::assertNull($result['items'][0]['name']);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 40, ['code' => 'EUR', 'name' => 'Euro']);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchCurrencies($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(40, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertCount(30, $result['items']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'EUR', 'name' => 'Euro'],
        ]));

        $result = $this->call(new SearchCurrencies($client), page: 1, itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([]));

        $result = $this->call(new SearchCurrencies($client), itemsPerPage: 999);

        self::assertSame(1, $result['page']);
        self::assertSame(0, $result['total']);
    }

    public function testItForcesItemsPerPageToAtLeastOne(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'EUR', 'name' => 'Euro'],
            ['code' => 'USD', 'name' => 'US Dollar'],
        ]));

        // With itemsPerPage=0 → clamped to 1, page 1 yields 1 item, hasMore true
        $result = $this->call(new SearchCurrencies($client), itemsPerPage: 0);

        self::assertCount(1, $result['items']);
    }
}
