<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Product;

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Product\SearchProducts;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProducts::class)]
final class SearchProductsTest extends McpToolTestCase
{
    private const string DEFAULT_LOCALE = 'en_US';

    public function testItForwardsFiltersAndPaginationToTheAdminApiClient(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with(
                'products',
                self::equalTo([
                    'translations.name' => 'mug',
                    'code' => 'CAP',
                    'productTaxons.taxon.code' => 'category',
                    'page' => 1,
                    'itemsPerPage' => 30,
                ]),
            )
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), name: 'mug', code: 'CAP', taxon: 'category');
    }

    /** The server trims the translations only when the caller announces the locale it reads. */
    public function testItAnnouncesTheLocaleItWillReadSoTheServerCanTrim(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with(
                'products',
                self::anything(),
                ['Prefer' => 'respond-for=lean; locale=fr_FR'],
            )
            ->willReturn(['hydra:member' => [], 'hydra:totalItems' => 0]);

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), locale: 'fr_FR');
    }

    public function testItFallsBackToTheConfiguredLocaleInThatHeader(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::anything(), ['Prefer' => 'respond-for=lean; locale=' . self::DEFAULT_LOCALE])
            ->willReturn(['hydra:member' => [], 'hydra:totalItems' => 0]);

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()));
    }

    public function testItOmitsEmptyAndNullFilters(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), name: '', code: null, taxon: '');
    }

    public function testItProjectsMembersToCodeAndNameOnly(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            $this->productMember('SKU_1', translations: ['en_US' => ['name' => 'Widget']], extras: ['enabled' => true, 'mainTaxon' => '/taxons/foo']),
        ], total: 1));

        $result = $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()));

        self::assertSame([['code' => 'SKU_1', 'name' => 'Widget']], $result['items']);
        self::assertCount(2, $result['items'][0], 'items[i] MUST contain only code and name');
    }

    public function testItUsesTheDefaultLocaleWhenLocaleArgIsOmitted(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            $this->productMember('SKU', translations: ['en_US' => ['name' => 'EN'], 'fr_FR' => ['name' => 'FR']]),
        ], total: 1));

        $result = $this->call(new SearchProducts($client, defaultLocale: 'fr_FR', adapter: $this->adapter()));

        self::assertSame('FR', $result['items'][0]['name']);
    }

    public function testItUsesTheExplicitLocaleArgWhenProvided(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            $this->productMember('SKU', translations: ['en_US' => ['name' => 'EN'], 'fr_FR' => ['name' => 'FR']]),
        ], total: 1));

        $result = $this->call(new SearchProducts($client, defaultLocale: 'en_US', adapter: $this->adapter()), locale: 'fr_FR');

        self::assertSame('FR', $result['items'][0]['name']);
    }

    public function testItFallsBackToTheFirstAvailableTranslationWhenLocaleMissing(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            $this->productMember('SKU', translations: ['de_DE' => ['name' => 'Widget DE']]),
        ], total: 1));

        $result = $this->call(new SearchProducts($client, defaultLocale: 'en_US', adapter: $this->adapter()));

        self::assertSame('Widget DE', $result['items'][0]['name']);
    }

    public function testItReturnsNullNameWhenNoTranslationsAtAll(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            $this->productMember('SKU', translations: []),
        ], total: 1));

        $result = $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()));

        self::assertNull($result['items'][0]['name']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), itemsPerPage: 999);
    }

    public function testItForcesItemsPerPageToAtLeastOne(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(static fn (array $q): bool => 1 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), itemsPerPage: 0);
    }

    public function testHasMoreIsTrueWhenTotalExceedsCurrentPage(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 30, $this->productMember('SKU', translations: ['en_US' => ['name' => 'X']])),
            total: 87,
        ));

        $result = $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(87, $result['total']);
        self::assertSame(1, $result['page']);
    }

    public function testHasMoreIsFalseOnLastPage(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 20, $this->productMember('SKU', translations: ['en_US' => ['name' => 'X']])),
            total: 20,
        ));

        $result = $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
    }

    public function testItForwardsTheRequestedPageToTheApi(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(static fn (array $q): bool => 2 === $q['page']))
            ->willReturn($this->collection([], total: 0));

        $result = $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), page: 2);

        self::assertSame(2, $result['page']);
    }

    public function testItForwardsAttributeEqAsNestedQuery(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(static fn (array $q): bool => ['t_shirt_brand' => 'Modern Wear'] === ($q['attribute'] ?? null)))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), attributeEq: ['t_shirt_brand' => 'Modern Wear']);
    }

    public function testItForwardsAttributePresentAsListQuery(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(static fn (array $q): bool => ['t_shirt_brand', 'discontinued'] === ($q['attribute_present'] ?? null)))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), attributePresent: ['t_shirt_brand', 'discontinued']);
    }

    public function testItForwardsAttributeRangeAsNestedQuery(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(static fn (array $q): bool => ['weight_g' => ['gte' => 100, 'lte' => 500]] === ($q['attribute_range'] ?? null)))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), attributeRange: ['weight_g' => ['gte' => 100, 'lte' => 500]]);
    }

    public function testItOmitsAttributeFiltersWhenArraysAreEmpty(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(
                static fn (array $q): bool => !array_key_exists('attribute', $q) &&
                    !array_key_exists('attribute_present', $q) &&
                    !array_key_exists('attribute_range', $q),
            ))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), attributeEq: [], attributePresent: [], attributeRange: []);
    }

    public function testItThrowsOnAttributeRangeWithUnknownOperator(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::never())->method('getDecoded');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only accepts "gte" and "lte"');

        /** @phpstan-ignore-next-line - intentionally malformed */
        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), attributeRange: ['weight_g' => ['between' => 100]]);
    }

    public function testItBuildsChannelIriFromCode(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(static fn (array $q): bool => '/api/v2/admin/channels/FASHION_WEB' === ($q['channel'] ?? null)))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), channel: 'FASHION_WEB');
    }

    public function testItForwardsPriceRangeAsNestedQueryWithRequiredChannel(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('products', self::callback(
                static fn (array $q): bool => ['channel' => 'FASHION_WEB', 'gte' => 1000, 'lte' => 5000] === ($q['price'] ?? null),
            ))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), priceRange: ['channel' => 'FASHION_WEB', 'gte' => 1000, 'lte' => 5000]);
    }

    public function testItThrowsWhenPriceRangeMissesChannel(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::never())->method('getDecoded');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a non-empty "channel"');

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), priceRange: ['gte' => 1000, 'lte' => 5000]);
    }

    public function testItThrowsWhenPriceRangeHasNeitherGteNorLte(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::never())->method('getDecoded');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of "gte" or "lte"');

        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), priceRange: ['channel' => 'FASHION_WEB']);
    }

    public function testItThrowsWhenPriceRangeBoundIsNotNumeric(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::never())->method('getDecoded');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a number');

        /** @phpstan-ignore-next-line - intentionally malformed */
        $this->call(new SearchProducts($client, self::DEFAULT_LOCALE, adapter: $this->adapter()), priceRange: ['channel' => 'WEB', 'gte' => 'cheap']);
    }

    /**
     * @param array<string, array<string, string>> $translations
     * @param array<string, mixed> $extras
     *
     * @return array<string, mixed>
     */
    private function productMember(string $code, array $translations, array $extras = []): array
    {
        return ['code' => $code, 'translations' => $translations] + $extras;
    }
}
