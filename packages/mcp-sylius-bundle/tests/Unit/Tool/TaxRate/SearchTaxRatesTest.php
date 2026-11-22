<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxRate;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxRate\SearchTaxRates;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchTaxRates::class)]
final class SearchTaxRatesTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchTaxRates::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_tax_rates', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesAllAndReturnsCuratedItems(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('tax-rates', ['page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([
                $this->rateMember('US_GST', 'US GST', 0.05, 'US', 'clothing'),
                $this->rateMember('EU_VAT', 'EU VAT', 0.2, 'EU', 'clothing'),
            ]));

        $result = $this->call(new SearchTaxRates($client));

        self::assertSame(2, $result['total']);
        self::assertSame('US_GST', $result['items'][0]['code']);
        self::assertSame(0.05, $result['items'][0]['amount']);
        self::assertSame('US', $result['items'][0]['zoneCode']);
        self::assertSame('clothing', $result['items'][0]['categoryCode']);
    }

    public function testItFiltersExactlyByZone(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->rateMember('US_GST', 'US GST', 0.05, 'US', 'clothing'),
            $this->rateMember('EU_VAT', 'EU VAT', 0.2, 'EU', 'clothing'),
        ]));

        $result = $this->call(new SearchTaxRates($client), zone: 'US');

        self::assertSame(1, $result['total']);
        self::assertSame('US_GST', $result['items'][0]['code']);
    }

    public function testItFiltersExactlyByCategory(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->rateMember('US_GST', 'US GST', 0.05, 'US', 'clothing'),
            $this->rateMember('EU_FOOD', 'EU Food', 0.1, 'EU', 'food'),
        ]));

        $result = $this->call(new SearchTaxRates($client), category: 'food');

        self::assertSame(1, $result['total']);
        self::assertSame('EU_FOOD', $result['items'][0]['code']);
    }

    public function testItFiltersPartiallyByCode(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->rateMember('US_GST', 'US GST', 0.05, 'US', 'clothing'),
            $this->rateMember('EU_VAT', 'EU VAT', 0.2, 'EU', 'clothing'),
        ]));

        $result = $this->call(new SearchTaxRates($client), code: 'us_');

        self::assertSame(1, $result['total']);
        self::assertSame('US_GST', $result['items'][0]['code']);
    }

    public function testHasMoreIsTrueWhenTotalExceedsPage(): void
    {
        $members = array_fill(0, 35, $this->rateMember('X', 'X', 0.05, 'US', 'all'));
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new SearchTaxRates($client), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(35, $result['total']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->rateMember('US_GST', 'US GST', 0.05, 'US', 'clothing'),
        ]));

        $result = $this->call(new SearchTaxRates($client), page: 1, itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rateMember(string $code, string $name, float $amount, string $zoneCode, string $categoryCode): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'amount' => $amount,
            'zone' => '/api/v2/admin/zones/' . $zoneCode,
            'category' => '/api/v2/admin/tax-categories/' . $categoryCode,
        ];
    }
}
