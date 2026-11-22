<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

final class Scenario10MetaDescriptionTest extends QaScenarioTestCase
{
    public function testTheEnglishMetaDescriptionOfTheTwoBestSellersIsReadThenRewritten(): void
    {
        $bestSellers = \array_slice(array_keys($this->unitsSoldPerProduct()), 0, 2);
        self::assertCount(2, $bestSellers);

        $before = $this->call('get_products', ['codes' => $bestSellers, 'locale' => 'en_US']);
        self::assertSame([], $before['missing']);
        /** @var list<array{code: string, name: string, metaDescription?: ?string}> $found */
        $found = $before['found'];
        $items = [];
        foreach ($found as $product) {
            self::assertArrayHasKey('metaDescription', $product, 'the current meta description is read');
            $items[] = [
                'code' => $product['code'],
                'locale' => 'en_US',
                'metaDescription' => sprintf('%s - shop it now at Fashion Web Store, free returns. (%s)', $product['name'], bin2hex(random_bytes(3))),
            ];
        }

        self::assertBatchApplied($this->call('upsert_products', ['items' => $items, 'mode' => 'update', 'confirm' => true]));

        /** @var list<array{code: string, metaDescription: ?string}> $read */
        $read = $this->call('get_products', ['codes' => $bestSellers, 'locale' => 'en_US'])['found'];
        $after = array_column($read, 'metaDescription', 'code');
        self::assertSame(array_column($items, 'metaDescription', 'code'), $after);
    }

    /**
     * @return array<string, int>
     */
    private function unitsSoldPerProduct(): array
    {
        $perVariant = [];
        /** @var list<array{tokenValue: string}> $orders */
        $orders = $this->searchAll('search_orders', ['state' => ['new', 'fulfilled']]);
        foreach ($orders as $order) {
            /** @var list<array{variantCode: string, quantity: int}> $items */
            $items = $this->searchAll('list_order_collection', ['tokenValue' => $order['tokenValue'], 'kind' => 'items']);
            foreach ($items as $item) {
                $perVariant[$item['variantCode']] = ($perVariant[$item['variantCode']] ?? 0) + $item['quantity'];
            }
        }
        self::assertNotEmpty($perVariant, 'units sold');

        $perProduct = [];
        foreach (array_chunk(array_keys($perVariant), 50) as $codes) {
            /** @var list<array{code: string, productCode: string}> $variants */
            $variants = $this->call('get_product_variants', ['codes' => $codes])['found'];
            foreach ($variants as $variant) {
                $perProduct[$variant['productCode']] = ($perProduct[$variant['productCode']] ?? 0) + $perVariant[$variant['code']];
            }
        }
        uksort($perProduct, static fn (string $a, string $b): int => [$perProduct[$b], $a] <=> [$perProduct[$a], $b]);

        return $perProduct;
    }
}
