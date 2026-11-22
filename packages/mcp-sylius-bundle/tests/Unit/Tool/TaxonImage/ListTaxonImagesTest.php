<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxonImage;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxonImage\SearchTaxonImages;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchTaxonImages::class)]
final class ListTaxonImagesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchTaxonImages::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_taxon_images', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesWithHardCapAndReturnsPaginatedItems(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons/category/images', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn([
                'member' => [
                    ['id' => 1, 'path' => 'a.png', 'type' => 'banner'],
                    ['id' => 2, 'path' => 'b.png', 'type' => 'thumbnail'],
                ],
            ]);

        $result = $this->call(new SearchTaxonImages($client), code: 'category');

        self::assertTrue($result['found']);
        self::assertSame('category', $result['code']);
        self::assertCount(2, $result['items']);
        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertFalse($result['hasMore']);
    }

    public function testItFiltersOnExactTypeMatch(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn([
            'member' => [
                ['id' => 1, 'path' => 'a.png', 'type' => 'banner'],
                ['id' => 2, 'path' => 'b.png', 'type' => 'thumbnail'],
                ['id' => 3, 'path' => 'c.png', 'type' => 'banner'],
            ],
        ]);

        $result = $this->call(new SearchTaxonImages($client), code: 'category', type: 'banner');

        self::assertCount(2, $result['items']);
        self::assertSame(1, $result['items'][0]['id']);
        self::assertSame(3, $result['items'][1]['id']);
    }

    public function testEmptyStringTypeFilterMatchesNullTypeOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn([
            'member' => [
                ['id' => 1, 'path' => 'a.png'],         // type key absent → null
                ['id' => 2, 'path' => 'b.png', 'type' => 'banner'],
                ['id' => 3, 'path' => 'c.png', 'type' => null],
            ],
        ]);

        $result = $this->call(new SearchTaxonImages($client), code: 'category', type: '');

        self::assertCount(2, $result['items']);
        self::assertSame(1, $result['items'][0]['id']);
        self::assertSame(3, $result['items'][1]['id']);
    }

    public function testItAppliesClientSidePagination(): void
    {
        $members = array_map(
            static fn (int $n): array => ['id' => $n, 'path' => "img{$n}.png"],
            range(1, 10),
        );
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['member' => $members]);

        $result = $this->call(new SearchTaxonImages($client), code: 'cat', page: 2, itemsPerPage: 3);

        self::assertSame(10, $result['total']);
        self::assertSame(2, $result['page']);
        self::assertCount(3, $result['items']);
        self::assertSame(4, $result['items'][0]['id']);
        self::assertTrue($result['hasMore']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        // Fill 60 items; with page=1 and itemsPerPage=50 (cap), only 50 are returned.
        $members = array_map(
            static fn (int $n): array => ['id' => $n, 'path' => "img{$n}.png"],
            range(1, 60),
        );
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['member' => $members]);

        $result = $this->call(new SearchTaxonImages($client), code: 'cat', page: 1, itemsPerPage: 999);

        self::assertCount(50, $result['items']);
        self::assertSame(60, $result['total']);
        self::assertTrue($result['hasMore']);
    }

    public function testItReturnsFalseWhenTaxonNotFound(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        $result = $this->call(new SearchTaxonImages($client), code: 'ghost');

        self::assertSame(['found' => false, 'code' => 'ghost'], $result);
    }

    public function testItRethrowsNon404ClientExceptions(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(500));

        $this->expectException(\Symfony\Component\HttpClient\Exception\ClientException::class);
        $this->call(new SearchTaxonImages($client), code: 'cat');
    }
}
