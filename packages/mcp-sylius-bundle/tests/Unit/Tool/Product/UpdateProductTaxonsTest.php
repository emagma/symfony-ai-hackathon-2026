<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Product;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Product\UpdateProductTaxons;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpdateProductTaxons::class)]
final class UpdateProductTaxonsTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(UpdateProductTaxons::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('update_product_taxons', $attributes[0]->newInstance()->name);
    }

    public function testItReturnsEmptyResultWhenItemsIsEmpty(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new UpdateProductTaxons($client, $this->audit(), $this->adapter()), items: [], confirm: true);

        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['total']);
        self::assertSame([], $result['results']);
    }

    public function testItReturnsItemsInvalidWhenItemIsMalformed(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(
            new UpdateProductTaxons($client, $this->audit(), $this->adapter()),
            items: [
                ['productCode' => 'p1', 'taxonCode' => 't1', 'operation' => 'invalid_op'],
            ],
            confirm: true,
        );

        self::assertSame(0, $result['updated']);
        self::assertSame('items_invalid', $result['reason']);
        self::assertNotEmpty($result['errors']);
    }

    public function testItReportsProductNotFoundWhenProductLookupFails(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(
            static function (array $paths): array {
                $first = (string) reset($paths);
                if (str_starts_with($first, 'products/')) {
                    return ['p1' => ['ok' => false, 'status' => 404, 'error' => 'Not Found']];
                }

                return ['t1' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/taxons/t1']]];
            },
        );

        $result = $this->call(
            new UpdateProductTaxons($client, $this->audit(), $this->adapter()),
            items: [['productCode' => 'p1', 'taxonCode' => 't1', 'operation' => 'add']],
            confirm: true,
        );

        self::assertSame(0, $result['updated']);
        self::assertSame('product_not_found', $result['results'][0]['reason']);
    }

    public function testItReportsAlreadyAttachedWhenAddingExistingPair(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(
            static function (array $paths): array {
                $first = (string) reset($paths);
                if (str_starts_with($first, 'products/')) {
                    return [
                        'p1' => [
                            'ok' => true,
                            'body' => [
                                '@id' => '/api/v2/admin/products/p1',
                                'productTaxons' => ['/api/v2/admin/product-taxons/42'],
                            ],
                        ],
                    ];
                }
                if (str_starts_with($first, 'taxons/')) {
                    return [
                        't1' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/taxons/t1']],
                    ];
                }

                // product-taxon hydration
                return [
                    '/api/v2/admin/product-taxons/42' => [
                        'ok' => true,
                        'body' => ['taxon' => '/api/v2/admin/taxons/t1', 'position' => 0],
                    ],
                ];
            },
        );
        $client->expects(self::never())->method('post');

        $result = $this->call(
            new UpdateProductTaxons($client, $this->audit(), $this->adapter()),
            items: [['productCode' => 'p1', 'taxonCode' => 't1', 'operation' => 'add']],
            confirm: true,
        );

        self::assertSame(0, $result['updated']);
        self::assertSame('already_attached', $result['results'][0]['reason']);
    }

    public function testItAddsProductTaxonAndRecordsAudit(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(
            static function (array $paths): array {
                $first = (string) reset($paths);
                if (str_starts_with($first, 'products/')) {
                    return [
                        'p1' => [
                            'ok' => true,
                            'body' => ['@id' => '/api/v2/admin/products/p1', 'productTaxons' => []],
                        ],
                    ];
                }

                return [
                    't1' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/taxons/t1']],
                ];
            },
        );
        $client->expects(self::once())
            ->method('post')
            ->with(
                'product-taxons',
                [
                    'product' => '/api/v2/admin/products/p1',
                    'taxon' => '/api/v2/admin/taxons/t1',
                    'position' => 0,
                ],
            )
            ->willReturn(['@id' => '/api/v2/admin/product-taxons/99', 'position' => 0]);

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'update_product_taxons' === $e->getTool() &&
                'product_taxon' === $e->getTargetType() &&
                'p1:t1' === $e->getTargetCode(),
        ));

        $result = $this->call(
            new UpdateProductTaxons($client, $audit, $this->adapter()),
            items: [['productCode' => 'p1', 'taxonCode' => 't1', 'operation' => 'add']],
            confirm: true,
        );

        self::assertSame(1, $result['updated']);
        self::assertSame(1, $result['total']);
        self::assertTrue($result['results'][0]['updated']);
        self::assertSame('add', $result['results'][0]['operation']);
        self::assertSame(0, $result['results'][0]['position']);
    }

    public function testItRemovesProductTaxonAndRecordsAudit(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(
            static function (array $paths): array {
                $first = (string) reset($paths);
                if (str_starts_with($first, 'products/')) {
                    return [
                        'p1' => [
                            'ok' => true,
                            'body' => [
                                '@id' => '/api/v2/admin/products/p1',
                                'productTaxons' => ['/api/v2/admin/product-taxons/42'],
                            ],
                        ],
                    ];
                }
                if (str_starts_with($first, 'taxons/')) {
                    return [
                        't1' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/taxons/t1']],
                    ];
                }

                // product-taxon hydration call
                return [
                    '/api/v2/admin/product-taxons/42' => [
                        'ok' => true,
                        'body' => ['taxon' => '/api/v2/admin/taxons/t1', 'position' => 5],
                    ],
                ];
            },
        );
        $client->method('deleteMany')->willReturn([0 => ['ok' => true]]);
        $client->expects(self::never())->method('post');

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'update_product_taxons' === $e->getTool() &&
                'p1:t1' === $e->getTargetCode(),
        ));

        $result = $this->call(
            new UpdateProductTaxons($client, $audit, $this->adapter()),
            items: [['productCode' => 'p1', 'taxonCode' => 't1', 'operation' => 'remove']],
            confirm: true,
        );

        self::assertSame(1, $result['updated']);
        self::assertTrue($result['results'][0]['updated']);
        self::assertSame('remove', $result['results'][0]['operation']);
    }

    public function testItReportsNotAttachedWhenRemovingMissingPair(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(
            static function (array $paths): array {
                $first = (string) reset($paths);
                if (str_starts_with($first, 'products/')) {
                    return [
                        'p1' => [
                            'ok' => true,
                            'body' => ['@id' => '/api/v2/admin/products/p1', 'productTaxons' => []],
                        ],
                    ];
                }

                return [
                    't1' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/taxons/t1']],
                ];
            },
        );
        $client->expects(self::never())->method('deleteMany');

        $result = $this->call(
            new UpdateProductTaxons($client, $this->audit(), $this->adapter()),
            items: [['productCode' => 'p1', 'taxonCode' => 't1', 'operation' => 'remove']],
            confirm: true,
        );

        self::assertSame(0, $result['updated']);
        self::assertSame('not_attached', $result['results'][0]['reason']);
    }

    public function testWithoutConfirmAndNullContextItReturnsPreviewOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(
            static function (array $paths): array {
                $first = (string) reset($paths);
                if (str_starts_with($first, 'products/')) {
                    return [
                        'p1' => [
                            'ok' => true,
                            'body' => ['@id' => '/api/v2/admin/products/p1', 'productTaxons' => []],
                        ],
                    ];
                }

                return [
                    't1' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/taxons/t1']],
                ];
            },
        );
        $client->expects(self::never())->method('post');

        $result = $this->call(
            new UpdateProductTaxons($client, $this->audit(), $this->adapter()),
            items: [['productCode' => 'p1', 'taxonCode' => 't1', 'operation' => 'add']],
            context: null,
        );

        self::assertSame(0, $result['updated']);
        self::assertSame('elicitation_unavailable_preview_only', $result['results'][0]['reason']);
        self::assertArrayHasKey('hint', $result);
    }

    public function testElicitationDeclinedDoesNotWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(
            static function (array $paths): array {
                $first = (string) reset($paths);
                if (str_starts_with($first, 'products/')) {
                    return [
                        'p1' => [
                            'ok' => true,
                            'body' => ['@id' => '/api/v2/admin/products/p1', 'productTaxons' => []],
                        ],
                    ];
                }

                return [
                    't1' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/taxons/t1']],
                ];
            },
        );
        $client->expects(self::never())->method('post');

        $tool = new UpdateProductTaxons($client, $this->audit(), $this->adapter());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                items: [['productCode' => 'p1', 'taxonCode' => 't1', 'operation' => 'add']],
                context: $context,
            ),
            ElicitAction::Decline,
        );

        self::assertSame(0, $result['updated']);
        self::assertSame('declined', $result['results'][0]['reason']);
    }
}
