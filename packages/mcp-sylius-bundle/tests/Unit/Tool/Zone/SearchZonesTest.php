<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Zone;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Zone\SearchZones;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchZones::class)]
final class SearchZonesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchZones::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_zones', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllZonesAndReturnsProjectedItems(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('zones', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([
                $this->zoneMember('EU', 'European Union', 'country', 'all'),
                $this->zoneMember('US_TAX', 'US Tax Zone', 'zone', 'tax'),
            ]));

        $result = $this->call(new SearchZones($client));

        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame('EU', $result['items'][0]['code']);
        self::assertSame('US_TAX', $result['items'][1]['code']);
    }

    public function testItFiltersExactlyByType(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->zoneMember('EU', 'EU', 'country', 'all'),
            $this->zoneMember('US_TAX', 'US Tax', 'zone', 'tax'),
        ]));

        $result = $this->call(new SearchZones($client), type: 'country');

        self::assertSame(1, $result['total']);
        self::assertSame('EU', $result['items'][0]['code']);
    }

    public function testItFiltersExactlyByScope(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->zoneMember('EU', 'EU', 'country', 'all'),
            $this->zoneMember('US_TAX', 'US Tax', 'zone', 'tax'),
        ]));

        $result = $this->call(new SearchZones($client), scope: 'tax');

        self::assertSame(1, $result['total']);
        self::assertSame('US_TAX', $result['items'][0]['code']);
    }

    public function testItFiltersPartiallyByCode(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->zoneMember('EU', 'European Union', 'country', 'all'),
            $this->zoneMember('US_TAX', 'US Tax', 'zone', 'tax'),
        ]));

        $result = $this->call(new SearchZones($client), code: 'eu');

        self::assertSame(1, $result['total']);
        self::assertSame('EU', $result['items'][0]['code']);
    }

    public function testItFiltersPartiallyByName(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->zoneMember('EU', 'European Union', 'country', 'all'),
            $this->zoneMember('US_TAX', 'US Tax', 'zone', 'tax'),
        ]));

        $result = $this->call(new SearchZones($client), name: 'union');

        self::assertSame(1, $result['total']);
        self::assertSame('EU', $result['items'][0]['code']);
    }

    public function testHasMoreIsTrueWhenTotalExceedsPage(): void
    {
        $members = array_fill(0, 35, $this->zoneMember('X', 'Zone', 'zone', 'all'));
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchZones($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(35, $result['total']);
        self::assertCount(30, $result['items']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->zoneMember('EU', 'EU', 'country', 'all'),
        ]));

        $result = $this->call(new SearchZones($client), page: 1, itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([]));

        $result = $this->call(new SearchZones($client), itemsPerPage: 999);

        self::assertSame(0, $result['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function zoneMember(string $code, string $name, string $type, string $scope): array
    {
        return ['code' => $code, 'name' => $name, 'type' => $type, 'scope' => $scope];
    }
}
