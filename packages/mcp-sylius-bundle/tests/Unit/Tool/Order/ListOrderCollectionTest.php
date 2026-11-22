<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Order;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Order\GetOrders;
use Emagma\McpSyliusBundle\Tool\Order\ListOrderCollection;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ListOrderCollection::class)]
final class ListOrderCollectionTest extends McpToolTestCase
{
    public function testItemsComeOffTheOrderAndTheOthersOffTheirNestedCollection(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path, array $query = []): array => match ((string) $path) {
            'orders/TOKEN' => ['tokenValue' => 'TOKEN', 'items' => [['id' => 1, 'quantity' => 2, 'variant' => ['code' => 'MUG_M']]]],
            'orders/TOKEN/payments' => $this->collection([['id' => 9, 'state' => 'completed', 'amount' => 1200]]),
            'orders/TOKEN/adjustments' => ['tax' => $query] === ['tax' => ['type' => 'tax']] ? $this->collection([['id' => 3, 'type' => 'tax', 'amount' => 200]]) : $this->collection([]),
            default => throw $this->clientException(404),
        });
        $tool = new ListOrderCollection($client);

        $items = $this->call($tool, tokenValue: 'TOKEN', kind: 'items');
        self::assertSame(['kind' => 'items', 'found' => true], array_intersect_key($items, ['kind' => 1, 'found' => 1]));
        self::assertSame(2, $items['items'][0]['quantity']);

        $payments = $this->call($tool, tokenValue: 'TOKEN', kind: 'payments');
        self::assertSame('completed', $payments['items'][0]['state']);

        $adjustments = $this->call($tool, tokenValue: 'TOKEN', kind: 'adjustments', type: 'tax');
        self::assertSame(200, $adjustments['items'][0]['amount']);

        $missing = $this->call($tool, tokenValue: 'NOPE', kind: 'shipments');
        self::assertSame(['found' => false, 'tokenValue' => 'NOPE', 'total' => 0], array_intersect_key($missing, ['found' => 1, 'tokenValue' => 1, 'total' => 1]));

        self::assertSame('invalid_kind', $this->call($tool, tokenValue: 'TOKEN', kind: 'units')['reason']);
    }

    public function testEachLineGetsTheProductOfItsVariantInOneFanOut(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['tokenValue' => 'TOKEN', 'items' => [
            ['id' => 1, 'quantity' => 2, 'variant' => '/api/v2/admin/product-variants/MUG_M'],
            ['id' => 2, 'quantity' => 1, 'variant' => '/api/v2/admin/product-variants/MUG_L'],
            ['id' => 3, 'quantity' => 1, 'variant' => '/api/v2/admin/product-variants/MUG_M'],
        ]]);
        $client->expects(self::once())->method('getDecodedMany')
            ->with(['MUG_M' => 'product-variants/MUG_M', 'MUG_L' => 'product-variants/MUG_L'])
            ->willReturn([
                'MUG_M' => ['ok' => true, 'body' => ['code' => 'MUG_M', 'product' => '/api/v2/admin/products/MUG']],
                'MUG_L' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            ]);

        $items = $this->call(new ListOrderCollection($client), tokenValue: 'TOKEN', kind: 'items')['items'];

        self::assertSame(['MUG', null, 'MUG'], array_column($items, 'productCode'), 'a variant gone since the order keeps a null product');
    }

    public function testGetOrdersAnswersByTokenValue(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecodedMany')
            ->with(['TOKEN' => 'orders/TOKEN', 'NOPE' => 'orders/NOPE'])
            ->willReturn([
                'TOKEN' => ['ok' => true, 'body' => ['tokenValue' => 'TOKEN', 'number' => '000000042', 'state' => 'new']],
                'NOPE' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            ]);

        $result = $this->call(new GetOrders($client, $this->grants(), $this->audit()), tokenValues: ['TOKEN', 'NOPE', 'TOKEN']);

        self::assertSame('000000042', $result['found'][0]['number']);
        self::assertSame(['NOPE'], $result['missing']);
    }
}
