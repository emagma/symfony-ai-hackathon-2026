<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductOption;

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductOption\SearchProductOptions;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProductOptions::class)]
final class SearchProductOptionsTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchProductOptions::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_product_options', $attributes[0]->newInstance()->name);
    }

    public function testItForwardsFiltersAndPaginationToTheApi(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-options', [
                'translations.name' => 'size',
                'code' => 'opt',
                'page' => 1,
                'itemsPerPage' => 30,
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductOptions($client, self::LOCALE), name: 'size', code: 'opt');
    }

    public function testItOmitsNullAndEmptyFilters(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-options', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductOptions($client, self::LOCALE), name: '', code: null);
    }

    public function testItProjectsMembersToCodeAndName(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'opt_size', 'translations' => [self::LOCALE => ['name' => 'Size']], 'position' => 1],
        ], total: 1));

        $result = $this->call(new SearchProductOptions($client, self::LOCALE));

        self::assertSame([['code' => 'opt_size', 'name' => 'Size']], $result['items']);
        self::assertCount(2, $result['items'][0], 'items[i] MUST contain only code and name');
    }

    public function testItUsesExplicitLocaleArg(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'opt_color', 'translations' => ['en_US' => ['name' => 'Color'], 'fr_FR' => ['name' => 'Couleur']]],
        ], total: 1));

        $result = $this->call(new SearchProductOptions($client, 'en_US'), locale: 'fr_FR');

        self::assertSame('Couleur', $result['items'][0]['name']);
    }

    public function testItFallsBackToFirstAvailableTranslation(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'opt_size', 'translations' => ['de_DE' => ['name' => 'Größe']]],
        ], total: 1));

        $result = $this->call(new SearchProductOptions($client, self::LOCALE));

        self::assertSame('Größe', $result['items'][0]['name']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-options', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductOptions($client, self::LOCALE), itemsPerPage: 999);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 30, ['code' => 'opt', 'translations' => [self::LOCALE => ['name' => 'X']]]),
            total: 87,
        ));

        $result = $this->call(new SearchProductOptions($client, self::LOCALE), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(87, $result['total']);
        self::assertSame(1, $result['page']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 10, ['code' => 'opt', 'translations' => [self::LOCALE => ['name' => 'X']]]),
            total: 10,
        ));

        $result = $this->call(new SearchProductOptions($client, self::LOCALE), itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }
}
