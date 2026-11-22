<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

final class Scenario13ChristmasHomepageTest extends QaScenarioTestCase
{
    public function testTheOrderLinesNameTheirProduct(): void
    {
        $orders = $this->paidOrders();
        self::assertNotEmpty($orders);
        $items = $this->orderLines($orders[0]);
        self::assertNotEmpty($items);

        // GAP: Sylius serializes `product` in sylius:admin:order_item:show only, not on orders/{token}/items.
        foreach ($items as $item) {
            self::assertNotNull($item['productCode'], sprintf('line of %s has no productCode', $item['variantCode']));
        }
    }

    /**
     * @return list<string> the tokenValue of every paid order of the channel
     */
    private function paidOrders(): array
    {
        /** @var list<string> $tokens */
        $tokens = array_column($this->searchAll('search_orders', ['paymentState' => ['paid'], 'channel' => self::CHANNEL]), 'tokenValue');

        return $tokens;
    }

    /**
     * @return list<array{productCode: string|null, variantCode: string, quantity: int}>
     */
    private function orderLines(string $tokenValue): array
    {
        /** @var list<array{productCode: string|null, variantCode: string, quantity: int}> $items */
        $items = $this->call('list_order_collection', ['tokenValue' => $tokenValue, 'kind' => 'items', 'itemsPerPage' => 50])['items'];

        return $items;
    }
}
