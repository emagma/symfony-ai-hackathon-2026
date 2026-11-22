<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAssociation;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAssociation\UpsertProductAssociations;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertProductAssociations::class)]
#[CoversClass(ReferenceResolver::class)]
final class ProductAssociationBatchToolsTest extends McpToolTestCase
{
    public function testThePairIsProbedByFilterReferencesAreResolvedOnceAndThePutGoesToTheId(): void
    {
        $mugSimilar = ['@id' => '/api/v2/admin/product-associations/7', 'id' => 7, 'owner' => '/api/v2/admin/products/MUG', 'type' => '/api/v2/admin/product-association-types/similar', 'associatedProducts' => ['/api/v2/admin/products/CAP']];
        $client = $this->apiClient();
        $fanOuts = [];
        $client->method('getDecodedMany')->willReturnCallback(function (array $paths) use ($mugSimilar, &$fanOuts): array {
            $fanOuts[] = array_map(strval(...), array_values($paths));
            $out = [];
            foreach ($paths as $key => $path) {
                $path = (string) $path;
                $out[$key] = match (true) {
                    str_contains($path, 'owner.code=MUG&type.code=similar') => ['ok' => true, 'body' => $this->collection([['owner' => '/api/v2/admin/products/MUGS', 'type' => '/api/v2/admin/product-association-types/similar'], $mugSimilar])],
                    str_starts_with($path, 'product-associations?') => ['ok' => true, 'body' => $this->collection([])],
                    \in_array($path, ['products/MUG', 'products/CAP', 'products/TSHIRT', 'product-association-types/accessories'], true) => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/' . $path]],
                    default => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
                };
            }

            return $out;
        });
        $client->expects(self::once())->method('postMany')
            ->with(['CAP/accessories' => ['path' => 'product-associations', 'body' => [
                'owner' => '/api/v2/admin/products/CAP', 'type' => '/api/v2/admin/product-association-types/accessories', 'associatedProducts' => ['/api/v2/admin/products/MUG'],
            ]]])
            ->willReturn(['CAP/accessories' => ['ok' => true, 'body' => ['id' => 8]]]);
        $client->expects(self::once())->method('putMany')
            ->with(['MUG/similar' => ['path' => 'product-associations/7', 'body' => array_replace($mugSimilar, ['associatedProducts' => ['/api/v2/admin/products/CAP', '/api/v2/admin/products/TSHIRT']])]])
            ->willReturn(['MUG/similar' => ['ok' => true, 'body' => $mugSimilar]]);

        $result = $this->call(new UpsertProductAssociations($client, new ReferenceResolver($client), $this->audit()), items: [
            ['ownerCode' => 'MUG', 'typeCode' => 'similar', 'associatedProductCodes' => ['CAP', 'TSHIRT']],
            ['ownerCode' => 'CAP', 'typeCode' => 'accessories', 'associatedProductCodes' => ['MUG']],
            ['ownerCode' => 'MUG', 'typeCode' => 'nope', 'associatedProductCodes' => ['CAP']],
            ['ownerCode' => 'MUG', 'typeCode' => 'accessories', 'associatedProductCodes' => ['GHOST']],
            ['ownerCode' => 'TSHIRT', 'typeCode' => 'accessories'],
        ], confirm: true);

        self::assertCount(2, $fanOuts, 'one probe fan-out, one reference fan-out');
        self::assertSame(['associatedProductCodes' => ['before' => ['CAP'], 'after' => ['CAP', 'TSHIRT']]], $result['results']['MUG/similar']['diff']);
        self::assertSame('created', $result['results']['CAP/accessories']['action']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'type_not_found', 'typeCode' => 'nope'], $result['results']['MUG/nope']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'associated_products_not_found', 'missing' => ['GHOST']], $result['results']['MUG/accessories']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['associatedProductCodes']], $result['results']['TSHIRT/accessories']);
    }
}
