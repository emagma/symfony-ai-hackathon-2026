<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\PaymentMethod;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\PaymentMethod\SearchPaymentMethods;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchPaymentMethods::class)]
final class SearchPaymentMethodsTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchPaymentMethods::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_payment_methods', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllAndReturnsProjectedItems(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('payment-methods', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([
                $this->member('cod', 'Cash on delivery', true),
                $this->member('credit_card', 'Credit card', true),
            ]));

        $result = $this->call(new SearchPaymentMethods($client, self::LOCALE));

        self::assertSame(2, $result['total']);
        self::assertSame('cod', $result['items'][0]['code']);
        self::assertSame('Cash on delivery', $result['items'][0]['name']);
        self::assertTrue($result['items'][0]['enabled']);
    }

    public function testItFiltersEnabledOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('pm1', 'Active', true),
            $this->member('pm2', 'Inactive', false),
        ]));

        $result = $this->call(new SearchPaymentMethods($client, self::LOCALE), enabled: false);

        self::assertSame(1, $result['total']);
        self::assertSame('pm2', $result['items'][0]['code']);
    }

    public function testItFiltersCodeCaseInsensitive(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('cash_on_delivery', 'Cash', true),
            $this->member('credit_card', 'Card', true),
        ]));

        $result = $this->call(new SearchPaymentMethods($client, self::LOCALE), code: 'CASH');

        self::assertSame(1, $result['total']);
        self::assertSame('cash_on_delivery', $result['items'][0]['code']);
    }

    public function testItFiltersChannel(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->member('pm1', 'PM1', true, channels: ['/api/v2/admin/channels/FASHION_WEB']),
            $this->member('pm2', 'PM2', true, channels: ['/api/v2/admin/channels/MOBILE']),
        ]));

        $result = $this->call(new SearchPaymentMethods($client, self::LOCALE), channel: 'FASHION_WEB');

        self::assertSame(1, $result['total']);
        self::assertSame('pm1', $result['items'][0]['code']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 60, $this->member('pm', 'PM', true)),
        ));

        $result = $this->call(new SearchPaymentMethods($client, self::LOCALE), itemsPerPage: 999);

        self::assertCount(50, $result['items']);
    }

    public function testItHasMoreWhenTotalExceedsPage(): void
    {
        $members = array_fill(0, 40, $this->member('pm', 'PM', true));
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchPaymentMethods($client, self::LOCALE), page: 1, itemsPerPage: 30);

        self::assertSame(40, $result['total']);
        self::assertTrue($result['hasMore']);
        self::assertCount(30, $result['items']);
    }

    public function testItUsesExplicitLocale(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            [
                'code' => 'pm1',
                'enabled' => true,
                'translations' => [
                    'en_US' => ['name' => 'EN'],
                    'fr_FR' => ['name' => 'FR'],
                ],
                'channels' => [],
            ],
        ]));

        $result = $this->call(new SearchPaymentMethods($client, defaultLocale: 'en_US'), locale: 'fr_FR');

        self::assertSame('FR', $result['items'][0]['name']);
    }

    /**
     * @param list<string> $channels
     *
     * @return array<string, mixed>
     */
    private function member(string $code, string $name, bool $enabled, array $channels = []): array
    {
        return [
            'code' => $code,
            'enabled' => $enabled,
            'translations' => [self::LOCALE => ['name' => $name]],
            'channels' => $channels,
        ];
    }
}
