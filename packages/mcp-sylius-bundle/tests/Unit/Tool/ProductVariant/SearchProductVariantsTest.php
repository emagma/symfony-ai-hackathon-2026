<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductVariant;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductVariant\SearchProductVariants;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProductVariants::class)]
final class SearchProductVariantsTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchProductVariants::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_product_variants', $attributes[0]->newInstance()->name);
    }

    public function testItForwardsFiltersAndPaginationToTheApi(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-variants', [
                'product' => '/api/v2/admin/products/MUG',
                'translations.name' => 'large',
                'code' => 'MUG_L',
                'page' => 1,
                'itemsPerPage' => 30,
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductVariants($client, self::LOCALE, $this->adapter()), product: 'MUG', name: 'large', code: 'MUG_L');
    }

    public function testItSendsTheProductFilterAsAnIri(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-variants', self::callback(
                // The vendor SearchFilter matches the relation: a bare code would silently return everything.
                static fn (array $q): bool => '/api/v2/admin/products/CAFE%20NOIR' === $q['product'],
            ))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductVariants($client, self::LOCALE, $this->adapter()), product: 'CAFE NOIR');
    }

    public function testItOmitsNullAndEmptyFilters(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-variants', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductVariants($client, self::LOCALE, $this->adapter()), product: '', name: '', code: null);
    }

    public function testItProjectsMembersToTheLeanShape(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            [
                'code' => 'MUG_BLUE',
                'translations' => [self::LOCALE => ['name' => 'Blue mug']],
                'product' => '/api/v2/admin/products/MUG',
                'enabled' => true,
                'onHand' => 12,
                'channelPricings' => ['WEB' => ['price' => 1500]],
            ],
        ], total: 1));

        $result = $this->call(new SearchProductVariants($client, self::LOCALE, $this->adapter()));

        self::assertSame([[
            'code' => 'MUG_BLUE',
            'name' => 'Blue mug',
            'productCode' => 'MUG',
            'enabled' => true,
            'onHand' => 12,
        ]], $result['items']);
        self::assertCount(5, $result['items'][0], 'items[i] MUST stay lean');
    }

    public function testItToleratesMissingOptionalFields(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'BARE'],
        ], total: 1));

        $result = $this->call(new SearchProductVariants($client, self::LOCALE, $this->adapter()));

        self::assertSame('BARE', $result['items'][0]['code']);
        self::assertNull($result['items'][0]['name']);
        self::assertNull($result['items'][0]['productCode']);
        self::assertFalse($result['items'][0]['enabled']);
        self::assertNull($result['items'][0]['onHand']);
    }

    public function testItUsesExplicitLocaleArg(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'MUG_BLUE', 'translations' => ['en_US' => ['name' => 'Blue mug'], 'fr_FR' => ['name' => 'Mug bleu']]],
        ], total: 1));

        $result = $this->call(new SearchProductVariants($client, 'en_US', $this->adapter()), locale: 'fr_FR');

        self::assertSame('Mug bleu', $result['items'][0]['name']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-variants', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductVariants($client, self::LOCALE, $this->adapter()), itemsPerPage: 999);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 30, ['code' => 'V']),
            total: 87,
        ));

        $result = $this->call(new SearchProductVariants($client, self::LOCALE, $this->adapter()), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(87, $result['total']);
        self::assertSame(1, $result['page']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 10, ['code' => 'V']),
            total: 10,
        ));

        $result = $this->call(new SearchProductVariants($client, self::LOCALE, $this->adapter()), itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }
}
