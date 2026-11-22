<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAttribute;

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAttribute\SearchProductAttributes;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProductAttributes::class)]
final class SearchProductAttributesTest extends McpToolTestCase
{
    private const string DEFAULT_LOCALE = 'en_US';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchProductAttributes::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_product_attributes', $attributes[0]->newInstance()->name);
    }

    public function testItForwardsFiltersAndPaginationToTheApi(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with(
                'product-attributes',
                [
                    'translations.name' => 'brand',
                    'code' => 'attr_',
                    'type' => 'text',
                    'page' => 1,
                    'itemsPerPage' => 30,
                ],
            )
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchProductAttributes($client, self::DEFAULT_LOCALE),
            name: 'brand',
            code: 'attr_',
            type: 'text',
        );
    }

    public function testItForwardsTranslatableFilterAsBooleanString(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with(
                'product-attributes',
                self::callback(static fn (array $q): bool => 'true' === ($q['translatable'] ?? null)),
            )
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductAttributes($client, self::DEFAULT_LOCALE), translatable: true);
    }

    public function testItForwardsTranslatableFalseAsStringFalse(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with(
                'product-attributes',
                self::callback(static fn (array $q): bool => 'false' === ($q['translatable'] ?? null)),
            )
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductAttributes($client, self::DEFAULT_LOCALE), translatable: false);
    }

    public function testItOmitsNullAndEmptyFilters(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-attributes', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductAttributes($client, self::DEFAULT_LOCALE), name: '', code: null, type: null);
    }

    public function testItProjectsMembersToCodeAndNameOnly(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'attr_brand', 'type' => 'text', 'translatable' => false, 'translations' => [
                self::DEFAULT_LOCALE => ['name' => 'Brand'],
            ]],
        ], total: 1));

        $result = $this->call(new SearchProductAttributes($client, self::DEFAULT_LOCALE));

        self::assertSame([['code' => 'attr_brand', 'name' => 'Brand']], $result['items']);
        self::assertCount(2, $result['items'][0], 'items[i] MUST contain only code and name');
    }

    public function testItUsesDefaultLocaleWhenLocaleArgIsOmitted(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'X', 'translations' => [
                'en_US' => ['name' => 'EN'],
                'fr_FR' => ['name' => 'FR'],
            ]],
        ], total: 1));

        $result = $this->call(new SearchProductAttributes($client, defaultLocale: 'fr_FR'));

        self::assertSame('FR', $result['items'][0]['name']);
    }

    public function testItUsesExplicitLocaleArgWhenProvided(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            ['code' => 'X', 'translations' => [
                'en_US' => ['name' => 'EN'],
                'fr_FR' => ['name' => 'FR'],
            ]],
        ], total: 1));

        $result = $this->call(new SearchProductAttributes($client, defaultLocale: 'en_US'), locale: 'fr_FR');

        self::assertSame('FR', $result['items'][0]['name']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-attributes', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductAttributes($client, self::DEFAULT_LOCALE), itemsPerPage: 999);
    }

    public function testItForcesItemsPerPageToAtLeastOne(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-attributes', self::callback(static fn (array $q): bool => 1 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductAttributes($client, self::DEFAULT_LOCALE), itemsPerPage: 0);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 30, ['code' => 'X', 'translations' => []]),
            total: 75,
        ));

        $result = $this->call(new SearchProductAttributes($client, self::DEFAULT_LOCALE), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(75, $result['total']);
        self::assertSame(1, $result['page']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 10, ['code' => 'X', 'translations' => []]),
            total: 10,
        ));

        $result = $this->call(new SearchProductAttributes($client, self::DEFAULT_LOCALE), itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }
}
