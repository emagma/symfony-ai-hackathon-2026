<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAssociation;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAssociation\SearchProductAssociations;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProductAssociations::class)]
#[CoversClass(ProductAssociationShaper::class)]
final class ProductAssociationToolsTest extends McpToolTestCase
{
    private const string PATH = 'product-associations/7';

    public function testListMapsFiltersToTheVendorNames(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-associations', [
                'owner.code' => 'MUG',
                'type.code' => 'similar',
                'page' => 1,
                'itemsPerPage' => 30,
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductAssociations($client), owner: 'MUG', type: 'similar');
    }

    public function testListProjectsCodesRatherThanIris(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([$this->association()], total: 1));

        $result = $this->call(new SearchProductAssociations($client));

        self::assertSame([[
            'id' => '7',
            'ownerCode' => 'MUG',
            'typeCode' => 'similar',
            'associatedProductCodes' => ['TSHIRT', 'CAP'],
        ]], $result['items']);
        self::assertArrayNotHasKey('found', $result['items'][0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function association(): array
    {
        return [
            // No `id` on purpose: Sylius exposes it only in the shop group.
            '@id' => '/api/v2/admin/product-associations/7',
            'owner' => '/api/v2/admin/products/MUG',
            'type' => '/api/v2/admin/product-association-types/similar',
            'associatedProducts' => [
                '/api/v2/admin/products/TSHIRT',
                '/api/v2/admin/products/CAP',
            ],
        ];
    }
}
