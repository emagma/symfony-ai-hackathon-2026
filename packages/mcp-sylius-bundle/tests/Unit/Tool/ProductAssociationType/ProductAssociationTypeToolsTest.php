<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAssociationType;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAssociationType\SearchProductAssociationTypes;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProductAssociationTypes::class)]
#[CoversClass(ProductAssociationTypeShaper::class)]
final class ProductAssociationTypeToolsTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    private const string PATH = 'product-association-types/similar';

    public function testListForwardsTheTranslatedNameFilter(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-association-types', [
                'translations.name' => 'simil',
                'code' => 'sim',
                'page' => 1,
                'itemsPerPage' => 30,
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductAssociationTypes($client, self::LOCALE), name: 'simil', code: 'sim');
    }

    public function testListResolvesTheNameForTheRequestedLocale(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([[
            'code' => 'similar',
            'translations' => [
                'en_US' => ['name' => 'Similar products'],
                'fr_FR' => ['name' => 'Produits similaires'],
            ],
        ]], total: 1));

        $result = $this->call(new SearchProductAssociationTypes($client, 'en_US'), locale: 'fr_FR');

        self::assertSame([['code' => 'similar', 'name' => 'Produits similaires']], $result['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function type(): array
    {
        return [
            '@id' => '/api/v2/admin/product-association-types/similar',
            'code' => 'similar',
            'translations' => [
                'en_US' => [
                    '@id' => '/api/v2/admin/product-association-type-translations/1',
                    'name' => 'Similar products',
                ],
                'fr_FR' => [
                    '@id' => '/api/v2/admin/product-association-type-translations/2',
                    'name' => 'Produits similaires',
                ],
            ],
        ];
    }
}
