<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxCategory;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxCategory\SearchTaxCategories;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchTaxCategories::class)]
final class SearchTaxCategoriesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchTaxCategories::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_tax_categories', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllTaxCategoriesInOneShotRequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('tax-categories', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([]));

        $result = $this->call(new SearchTaxCategories($client));

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testItProjectsMembersToCodeAndName(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'tax_standard', 'name' => 'Standard Tax', 'description' => 'Default'],
            ['code' => 'tax_reduced', 'name' => 'Reduced Tax', 'description' => 'Lower'],
        ]));

        $result = $this->call(new SearchTaxCategories($client));

        self::assertCount(2, $result['items']);
        self::assertSame('tax_standard', $result['items'][0]['code']);
        self::assertSame('Standard Tax', $result['items'][0]['name']);
        self::assertCount(2, $result['items'][0], 'items[i] MUST contain only code and name');
    }

    public function testItFiltersClientSideByCodeCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'tax_standard', 'name' => 'Standard Tax'],
            ['code' => 'tax_reduced', 'name' => 'Reduced Tax'],
            ['code' => 'tax_zero', 'name' => 'Zero Tax'],
        ]));

        $result = $this->call(new SearchTaxCategories($client), code: 'reduced');

        self::assertCount(1, $result['items']);
        self::assertSame('tax_reduced', $result['items'][0]['code']);
    }

    public function testItFiltersClientSideByNameCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'tax_standard', 'name' => 'Standard Tax'],
            ['code' => 'tax_zero', 'name' => 'Zero Tax'],
        ]));

        $result = $this->call(new SearchTaxCategories($client), name: 'zero');

        self::assertCount(1, $result['items']);
        self::assertSame('tax_zero', $result['items'][0]['code']);
    }

    public function testItReturnsNullNameWhenMissing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'tax_standard'],
        ]));

        $result = $this->call(new SearchTaxCategories($client));

        self::assertNull($result['items'][0]['name']);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 40, ['code' => 'tax_std', 'name' => 'Standard']);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchTaxCategories($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(40, $result['total']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'tax_standard', 'name' => 'Standard Tax'],
        ]));

        $result = $this->call(new SearchTaxCategories($client));

        self::assertFalse($result['hasMore']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 60, ['code' => 'tax_std', 'name' => 'Standard']);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchTaxCategories($client), itemsPerPage: 999);

        self::assertCount(50, $result['items']);
    }

    public function testItForcesItemsPerPageToAtLeastOne(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'tax_standard', 'name' => 'Standard'],
            ['code' => 'tax_reduced', 'name' => 'Reduced'],
        ]));

        $result = $this->call(new SearchTaxCategories($client), itemsPerPage: 0);

        self::assertCount(1, $result['items']);
    }
}
