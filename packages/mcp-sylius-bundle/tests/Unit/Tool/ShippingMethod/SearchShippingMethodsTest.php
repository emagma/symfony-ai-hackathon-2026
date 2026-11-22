<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ShippingMethod;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ShippingMethod\SearchShippingMethods;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchShippingMethods::class)]
final class SearchShippingMethodsTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchShippingMethods::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_shipping_methods', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllAndReturnsProjectedItems(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('shipping-methods', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([
                $this->member('ups_ground', 'UPS Ground', true),
                $this->member('fedex_express', 'FedEx Express', false),
            ]));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE));

        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertFalse($result['hasMore']);
        self::assertSame('ups_ground', $result['items'][0]['code']);
        self::assertSame('UPS Ground', $result['items'][0]['name']);
        self::assertTrue($result['items'][0]['enabled']);
    }

    public function testItFiltersEnabledOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('sm1', 'Active', true),
            $this->member('sm2', 'Inactive', false),
        ]));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE), enabled: true);

        self::assertSame(1, $result['total']);
        self::assertSame('sm1', $result['items'][0]['code']);
    }

    public function testItFiltersCodeCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('ups_ground', 'UPS Ground', true),
            $this->member('fedex_express', 'FedEx Express', true),
        ]));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE), code: 'UPS');

        self::assertSame(1, $result['total']);
        self::assertSame('ups_ground', $result['items'][0]['code']);
    }

    public function testItFiltersNameCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('sm1', 'Standard shipping', true),
            $this->member('sm2', 'Express', true),
        ]));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE), name: 'standard');

        self::assertSame(1, $result['total']);
        self::assertSame('sm1', $result['items'][0]['code']);
    }

    public function testItFiltersChannel(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('sm1', 'SM1', true, channels: ['/api/v2/admin/channels/FASHION_WEB']),
            $this->member('sm2', 'SM2', true, channels: ['/api/v2/admin/channels/MOBILE']),
        ]));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE), channel: 'FASHION_WEB');

        self::assertSame(1, $result['total']);
        self::assertSame('sm1', $result['items'][0]['code']);
    }

    public function testItFiltersZone(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('sm1', 'SM1', true, zone: '/api/v2/admin/zones/US'),
            $this->member('sm2', 'SM2', true, zone: '/api/v2/admin/zones/EU'),
        ]));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE), zone: 'US');

        self::assertSame(1, $result['total']);
        self::assertSame('sm1', $result['items'][0]['code']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 60, $this->member('sm', 'SM', true)),
        ));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE), itemsPerPage: 999);

        self::assertCount(50, $result['items']);
    }

    public function testItHasMoreWhenTotalExceedsPage(): void
    {
        $members = array_fill(0, 40, $this->member('sm', 'SM', true));
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE), page: 1, itemsPerPage: 30);

        self::assertSame(40, $result['total']);
        self::assertTrue($result['hasMore']);
        self::assertCount(30, $result['items']);
    }

    public function testItUsesExplicitLocale(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            [
                'code' => 'sm1',
                'enabled' => true,
                'translations' => [
                    'en_US' => ['name' => 'EN'],
                    'fr_FR' => ['name' => 'FR'],
                ],
                'channels' => [],
            ],
        ]));

        $result = $this->call(new SearchShippingMethods($client, defaultLocale: 'en_US'), locale: 'fr_FR');

        self::assertSame('FR', $result['items'][0]['name']);
    }

    /**
     * @return array<string, mixed>
     */
    public function testItOmitsTheExistsFilterByDefault(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            // Without exists[archivedAt], Sylius' NonArchivedExtension keeps the active ones only.
            ->with('shipping-methods', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([]));

        $this->call(new SearchShippingMethods($client, self::LOCALE));
    }

    public function testItAsksForArchivedMethodsExplicitly(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('shipping-methods', [
                'page' => 1,
                'itemsPerPage' => 1000,
                'exists' => ['archivedAt' => 'true'],
            ])
            ->willReturn($this->collection([]));

        $this->call(new SearchShippingMethods($client, self::LOCALE), archived: true);
    }

    public function testItAsksForActiveMethodsExplicitly(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('shipping-methods', [
                'page' => 1,
                'itemsPerPage' => 1000,
                'exists' => ['archivedAt' => 'false'],
            ])
            ->willReturn($this->collection([]));

        $this->call(new SearchShippingMethods($client, self::LOCALE), archived: false);
    }

    public function testItReportsTheArchivedStatePerItem(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('ups_ground', 'UPS Ground', true),
            ['code' => 'old_carrier', 'enabled' => false, 'archivedAt' => '2026-09-07T10:00:00+00:00'],
        ]));

        $result = $this->call(new SearchShippingMethods($client, self::LOCALE));

        self::assertFalse($result['items'][0]['archived']);
        self::assertTrue($result['items'][1]['archived']);
    }

    private function member(string $code, string $name, bool $enabled, array $channels = [], ?string $zone = null): array
    {
        $m = [
            'code' => $code,
            'enabled' => $enabled,
            'translations' => [self::LOCALE => ['name' => $name]],
            'channels' => $channels,
        ];
        if (null !== $zone) {
            $m['zone'] = $zone;
        }

        return $m;
    }
}
