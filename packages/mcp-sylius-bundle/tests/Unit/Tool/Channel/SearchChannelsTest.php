<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Channel;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Channel\SearchChannels;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchChannels::class)]
final class SearchChannelsTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchChannels::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_channels', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllAndReturnsProjectedItems(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('channels', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([
                $this->member('FASHION_WEB', 'Fashion Web', true),
                $this->member('MOBILE', 'Mobile App', false),
            ]));

        $result = $this->call(new SearchChannels($client));

        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertFalse($result['hasMore']);
        self::assertSame('FASHION_WEB', $result['items'][0]['code']);
        self::assertSame('Fashion Web', $result['items'][0]['name']);
        self::assertTrue($result['items'][0]['enabled']);
    }

    public function testItFiltersEnabledOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('WEB', 'Web', true),
            $this->member('APP', 'App', false),
        ]));

        $result = $this->call(new SearchChannels($client), enabled: true);

        self::assertSame(1, $result['total']);
        self::assertSame('WEB', $result['items'][0]['code']);
    }

    public function testItFiltersCodeCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('FASHION_WEB', 'Fashion Web', true),
            $this->member('MOBILE', 'Mobile', true),
        ]));

        $result = $this->call(new SearchChannels($client), code: 'fashion');

        self::assertSame(1, $result['total']);
        self::assertSame('FASHION_WEB', $result['items'][0]['code']);
    }

    public function testItFiltersNameCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('WEB', 'Fashion Web Store', true),
            $this->member('APP', 'Mobile App', true),
        ]));

        $result = $this->call(new SearchChannels($client), name: 'mobile');

        self::assertSame(1, $result['total']);
        self::assertSame('APP', $result['items'][0]['code']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 60, $this->member('WEB', 'Web', true)),
        ));

        $result = $this->call(new SearchChannels($client), itemsPerPage: 999);

        self::assertCount(50, $result['items']);
    }

    public function testItHasMoreWhenTotalExceedsPage(): void
    {
        $members = array_fill(0, 35, $this->member('WEB', 'Web', true));
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchChannels($client), page: 1, itemsPerPage: 30);

        self::assertSame(35, $result['total']);
        self::assertTrue($result['hasMore']);
        self::assertCount(30, $result['items']);
    }

    public function testItHasMoreFalseOnLastPage(): void
    {
        $members = array_fill(0, 10, $this->member('WEB', 'Web', true));
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchChannels($client), page: 1, itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }

    /**
     * @return array<string, mixed>
     */
    private function member(string $code, string $name, bool $enabled): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'enabled' => $enabled,
        ];
    }
}
