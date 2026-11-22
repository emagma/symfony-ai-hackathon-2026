<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Country;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Country\SearchCountries;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchCountries::class)]
final class SearchCountriesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchCountries::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_countries', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllCountriesInOneShotRequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('countries', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([]));

        $result = $this->call(new SearchCountries($client));

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testItProjectsMembersToCodeNameAndEnabled(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'JP', 'name' => 'Japan', 'enabled' => true],
            ['code' => 'FR', 'name' => 'France', 'enabled' => false],
        ]));

        $result = $this->call(new SearchCountries($client));

        self::assertCount(2, $result['items']);
        self::assertSame('JP', $result['items'][0]['code']);
        self::assertSame('Japan', $result['items'][0]['name']);
        self::assertTrue($result['items'][0]['enabled']);
        self::assertCount(3, $result['items'][0], 'items[i] MUST contain code, name, enabled');
    }

    public function testItFiltersClientSideByCodeCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'JP', 'name' => 'Japan', 'enabled' => true],
            ['code' => 'FR', 'name' => 'France', 'enabled' => true],
            ['code' => 'DE', 'name' => 'Germany', 'enabled' => true],
        ]));

        $result = $this->call(new SearchCountries($client), code: 'jp');

        self::assertCount(1, $result['items']);
        self::assertSame('JP', $result['items'][0]['code']);
    }

    public function testItFiltersClientSideByNameCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'JP', 'name' => 'Japan', 'enabled' => true],
            ['code' => 'FR', 'name' => 'France', 'enabled' => true],
        ]));

        $result = $this->call(new SearchCountries($client), name: 'france');

        self::assertCount(1, $result['items']);
        self::assertSame('FR', $result['items'][0]['code']);
    }

    public function testItFiltersClientSideByEnabledFlag(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'JP', 'name' => 'Japan', 'enabled' => true],
            ['code' => 'FR', 'name' => 'France', 'enabled' => false],
            ['code' => 'DE', 'name' => 'Germany', 'enabled' => true],
        ]));

        $result = $this->call(new SearchCountries($client), enabled: false);

        self::assertCount(1, $result['items']);
        self::assertSame('FR', $result['items'][0]['code']);
    }

    public function testItReturnsNullNameWhenMissing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'JP', 'enabled' => true],
        ]));

        $result = $this->call(new SearchCountries($client));

        self::assertNull($result['items'][0]['name']);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 40, ['code' => 'JP', 'name' => 'Japan', 'enabled' => true]);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchCountries($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(40, $result['total']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'JP', 'name' => 'Japan', 'enabled' => true],
        ]));

        $result = $this->call(new SearchCountries($client));

        self::assertFalse($result['hasMore']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 60, ['code' => 'JP', 'name' => 'Japan', 'enabled' => true]);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchCountries($client), itemsPerPage: 999);

        self::assertCount(50, $result['items']);
    }

    public function testItForcesItemsPerPageToAtLeastOne(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'JP', 'name' => 'Japan', 'enabled' => true],
            ['code' => 'FR', 'name' => 'France', 'enabled' => true],
        ]));

        $result = $this->call(new SearchCountries($client), itemsPerPage: 0);

        self::assertCount(1, $result['items']);
    }
}
