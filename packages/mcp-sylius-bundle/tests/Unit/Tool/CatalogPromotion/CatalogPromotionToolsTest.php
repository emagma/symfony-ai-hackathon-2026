<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\CatalogPromotion;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\CatalogPromotion\SearchCatalogPromotions;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchCatalogPromotions::class)]
#[CoversClass(CatalogPromotionShaper::class)]
final class CatalogPromotionToolsTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    private const string PATH = 'catalog-promotions/winter';

    public function testListSendsTheVendorFilters(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('catalog-promotions', [
                'page' => 1,
                'itemsPerPage' => 30,
                'name' => 'winter',
                'state' => 'active',
                'enabled' => 'false',
                'startDate' => ['after' => '2026-01-01'],
                'order' => ['priority' => 'asc'],
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchCatalogPromotions($client),
            name: 'winter',
            state: 'active',
            enabled: false,
            startDateAfter: '2026-01-01',
            sort: 'priority',
            direction: 'asc',
        );
    }

    public function testListKeepsScopesOutOfTheListing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([$this->catalogPromotion()], total: 1));

        $item = $this->call(new SearchCatalogPromotions($client))['items'][0];

        // A for_variants scope carries one code per variant: too much for a listing.
        self::assertArrayNotHasKey('scopes', $item);
        self::assertSame('active', $item['state']);
        self::assertSame(['WEB'], $item['channels']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function clientExceptionWithBody(
        int $status,
        array $body,
    ): \Symfony\Component\HttpClient\Exception\ClientException {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getInfo')->willReturnCallback(
            static fn (?string $type = null) => match ($type) {
                'http_code' => $status,
                'url' => 'https://test/',
                'response_headers' => [\sprintf('HTTP/1.1 %d', $status)],
                default => null,
            },
        );
        $response->method('toArray')->willReturn($body);

        return new \Symfony\Component\HttpClient\Exception\ClientException($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogPromotion(): array
    {
        return [
            '@id' => '/api/v2/admin/catalog-promotions/winter',
            'code' => 'winter',
            'name' => 'Winter sale',
            'enabled' => true,
            'exclusive' => false,
            'state' => 'active',
            'priority' => 1,
            'startDate' => null,
            'endDate' => null,
            'channels' => ['/api/v2/admin/channels/WEB'],
            'scopes' => [[
                '@id' => '/api/v2/admin/catalog-promotions/winter/scopes/1',
                'id' => 1,
                'type' => 'for_taxons',
                'configuration' => ['taxons' => ['caps']],
            ]],
            'actions' => [[
                '@id' => '/api/v2/admin/catalog-promotions/winter/actions/1',
                'id' => 1,
                'type' => 'percentage_discount',
                'configuration' => ['amount' => 0.5],
            ]],
            'translations' => [
                'en_US' => [
                    '@id' => '/api/v2/admin/catalog-promotions/winter/translations/en_US',
                    'label' => 'Winter sale',
                    'description' => 'Cold days',
                ],
            ],
        ];
    }
}
