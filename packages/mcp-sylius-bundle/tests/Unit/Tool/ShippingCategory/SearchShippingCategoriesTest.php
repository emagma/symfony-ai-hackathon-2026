<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ShippingCategory;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ShippingCategory\SearchShippingCategories;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchShippingCategories::class)]
final class SearchShippingCategoriesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchShippingCategories::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_shipping_categories', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllShippingCategoriesInOneShotRequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('shipping-categories', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([]));

        $result = $this->call(new SearchShippingCategories($client));

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testItProjectsMembersToCodeAndName(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'sc_standard', 'name' => 'Standard Shipping', 'description' => 'Default'],
            ['code' => 'sc_express', 'name' => 'Express Shipping', 'description' => 'Fast'],
        ]));

        $result = $this->call(new SearchShippingCategories($client));

        self::assertCount(2, $result['items']);
        self::assertSame('sc_standard', $result['items'][0]['code']);
        self::assertSame('Standard Shipping', $result['items'][0]['name']);
        self::assertCount(2, $result['items'][0], 'items[i] MUST contain only code and name');
    }

    public function testItFiltersClientSideByCodeCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'sc_standard', 'name' => 'Standard Shipping'],
            ['code' => 'sc_express', 'name' => 'Express Shipping'],
            ['code' => 'sc_heavy', 'name' => 'Heavy Items'],
        ]));

        $result = $this->call(new SearchShippingCategories($client), code: 'express');

        self::assertCount(1, $result['items']);
        self::assertSame('sc_express', $result['items'][0]['code']);
    }

    public function testItFiltersClientSideByNameCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'sc_standard', 'name' => 'Standard Shipping'],
            ['code' => 'sc_heavy', 'name' => 'Heavy Items'],
        ]));

        $result = $this->call(new SearchShippingCategories($client), name: 'heavy');

        self::assertCount(1, $result['items']);
        self::assertSame('sc_heavy', $result['items'][0]['code']);
    }

    public function testItReturnsNullNameWhenMissing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'sc_standard'],
        ]));

        $result = $this->call(new SearchShippingCategories($client));

        self::assertNull($result['items'][0]['name']);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 40, ['code' => 'sc_std', 'name' => 'Standard']);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchShippingCategories($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(40, $result['total']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'sc_standard', 'name' => 'Standard Shipping'],
        ]));

        $result = $this->call(new SearchShippingCategories($client));

        self::assertFalse($result['hasMore']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $members = array_fill(0, 60, ['code' => 'sc_std', 'name' => 'Standard']);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchShippingCategories($client), itemsPerPage: 999);

        self::assertCount(50, $result['items']);
    }

    public function testItForcesItemsPerPageToAtLeastOne(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'sc_standard', 'name' => 'Standard'],
            ['code' => 'sc_express', 'name' => 'Express'],
        ]));

        $result = $this->call(new SearchShippingCategories($client), itemsPerPage: 0);

        self::assertCount(1, $result['items']);
    }
}
