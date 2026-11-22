<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Locale;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Locale\SearchLocales;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchLocales::class)]
final class SearchLocalesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchLocales::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_locales', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllLocalesInOneShotRequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('locales', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([]));

        $result = $this->call(new SearchLocales($client));

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testItProjectsMembersToCodeAndName(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'en_US', 'name' => 'English (United States)'],
            ['code' => 'fr_FR', 'name' => 'French (France)'],
        ]));

        $result = $this->call(new SearchLocales($client));

        self::assertCount(2, $result['items']);
        self::assertSame('en_US', $result['items'][0]['code']);
        self::assertSame('English (United States)', $result['items'][0]['name']);
        self::assertCount(2, $result['items'][0], 'items[i] MUST contain only code and name');
    }

    public function testItFiltersClientSideByCodeCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'en_US', 'name' => 'English (United States)'],
            ['code' => 'fr_FR', 'name' => 'French (France)'],
            ['code' => 'de_DE', 'name' => 'German (Germany)'],
        ]));

        $result = $this->call(new SearchLocales($client), code: 'fr');

        self::assertCount(1, $result['items']);
        self::assertSame('fr_FR', $result['items'][0]['code']);
    }

    public function testItFiltersClientSideByNameCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'en_US', 'name' => 'English (United States)'],
            ['code' => 'fr_FR', 'name' => 'French (France)'],
        ]));

        $result = $this->call(new SearchLocales($client), name: 'french');

        self::assertCount(1, $result['items']);
        self::assertSame('fr_FR', $result['items'][0]['code']);
    }

    public function testItReturnsNullNameWhenMissing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'en_US'],
        ]));

        $result = $this->call(new SearchLocales($client));

        self::assertNull($result['items'][0]['name']);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 40, ['code' => 'en_US', 'name' => 'English']);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchLocales($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(40, $result['total']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'en_US', 'name' => 'English'],
        ]));

        $result = $this->call(new SearchLocales($client), page: 1, itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 60, ['code' => 'en_US', 'name' => 'English']);
        $client->method('getDecoded')->willReturn($this->collection($members));

        // Request 999, should cap at 50, so page 1 yields 50 items
        $result = $this->call(new SearchLocales($client), itemsPerPage: 999);

        self::assertCount(50, $result['items']);
    }

    public function testItForcesItemsPerPageToAtLeastOne(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'en_US', 'name' => 'English'],
            ['code' => 'fr_FR', 'name' => 'French'],
        ]));

        $result = $this->call(new SearchLocales($client), itemsPerPage: 0);

        self::assertCount(1, $result['items']);
    }
}
