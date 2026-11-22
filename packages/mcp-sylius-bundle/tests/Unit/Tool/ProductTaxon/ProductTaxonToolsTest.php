<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductTaxon;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Output\ProductTaxon\ProductTaxonOutput;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductTaxon\SearchProductTaxons;
use Emagma\McpSyliusBundle\Tool\ProductTaxon\UpdateProductTaxonPosition;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProductTaxons::class)]
#[CoversClass(UpdateProductTaxonPosition::class)]
#[CoversClass(ProductTaxonShaper::class)]
final class ProductTaxonToolsTest extends McpToolTestCase
{
    public function testTheToolsDeclareTheirMcpAttribute(): void
    {
        $expected = [
            SearchProductTaxons::class => 'search_product_taxons',
            UpdateProductTaxonPosition::class => 'update_product_taxon_position',
        ];

        foreach ($expected as $class => $name) {
            $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);
            self::assertCount(1, $attributes);
            self::assertSame($name, $attributes[0]->newInstance()->name);
        }
    }

    public function testListOrdersByPositionByDefault(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-taxons', [
                'taxon.code' => 'mugs',
                'page' => 1,
                'itemsPerPage' => 30,
                'order' => ['position' => 'asc'],
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductTaxons($client), taxon: 'mugs');
    }

    public function testListCanKeepTheApiOrder(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-taxons', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductTaxons($client), sortByPosition: false);
    }

    public function testListProjectsTheAttachment(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([$this->attachment()], total: 1));

        $result = $this->call(new SearchProductTaxons($client));

        self::assertSame([[
            'id' => '11',
            'productCode' => 'MUG',
            'taxonCode' => 'mugs',
            'position' => 3,
        ]], $result['items']);
    }

    public function testItFallsBackToTheIriForTheId(): void
    {
        $shaped = ProductTaxonOutput::fromPayload([
            '@id' => '/api/v2/admin/product-taxons/42',
            'product' => '/api/v2/admin/products/MUG',
        ]);

        self::assertSame('42', $shaped->id);
        self::assertNull($shaped->position);
    }

    public function testUpdateReturnsNotFoundOn404(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('put');

        $result = $this->call(new UpdateProductTaxonPosition($client, $this->audit()), '99', 1, confirm: true);

        self::assertSame('not_found', $result['reason']);
    }

    public function testUpdateReturnsNoChangeAtTheSamePosition(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->attachment());
        $client->expects(self::never())->method('put');

        $result = $this->call(new UpdateProductTaxonPosition($client, $this->audit()), '11', 3, confirm: true);

        self::assertSame('no_change', $result['reason']);
    }

    public function testUpdateRoundTripsThePayloadAndRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->attachment());
        $client->expects(self::once())
            ->method('put')
            ->with('product-taxons/11', self::callback(static function (array $body): bool {
                self::assertSame(0, $body['position']);
                // The admin PUT is not partial: the rest has to travel back.
                self::assertSame('/api/v2/admin/products/MUG', $body['product']);
                self::assertSame('/api/v2/admin/taxons/mugs', $body['taxon']);

                return true;
            }), [])
            ->willReturn(['id' => 11, 'product' => '/api/v2/admin/products/MUG', 'taxon' => '/api/v2/admin/taxons/mugs', 'position' => 0]);

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'update_product_taxon_position' === $e->getTool() &&
                'product_taxon' === $e->getTargetType() &&
                '11' === $e->getTargetCode(),
        ));

        $result = $this->call(new UpdateProductTaxonPosition($client, $audit), '11', 0, confirm: true);

        self::assertTrue($result['updated']);
        self::assertSame(['position' => ['before' => 3, 'after' => 0]], $result['diff']);
    }

    public function testUpdateRefusesWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->attachment());
        $client->expects(self::never())->method('put');

        $result = $this->call(new UpdateProductTaxonPosition($client, $this->audit()), '11', 0, context: $this->noElicitContext());

        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationDeclinedOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->attachment());
        $client->expects(self::never())->method('put');

        $tool = new UpdateProductTaxonPosition($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('11', 0, confirm: true, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['updated']);
        self::assertSame('declined', $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function attachment(): array
    {
        return [
            '@id' => '/api/v2/admin/product-taxons/11',
            'id' => 11,
            'product' => '/api/v2/admin/products/MUG',
            'taxon' => '/api/v2/admin/taxons/mugs',
            'position' => 3,
        ];
    }
}
