<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

final class Scenario01DeliveryIncidentTest extends QaScenarioTestCase
{
    private const string PRODUCT = 'Stellar_Drift_T_Shirt';

    public function testTheStockOfTheProductIsReadPerSize(): void
    {
        $stock = [];
        foreach ($this->variantsOfTheProduct() as $variant) {
            self::assertTrue($variant['tracked'], $variant['code'] . ' is tracked');
            $stock[$variant['name']] = $variant['onHand'];
        }

        // The fixture's stock (S 3, M 11, L 2, XL 8, XXL 6), less the 9 M sold.
        self::assertSame(['S' => 3, 'M' => 2, 'L' => 2, 'XL' => 8, 'XXL' => 6], $stock);
    }

    public function testThePaidUnshippedOrdersOfASizeComeWithEverythingTheMessagesNeed(): void
    {
        $sizeM = null;
        foreach ($this->variantsOfTheProduct() as $variant) {
            if (\in_array('t_shirt_size_m', $variant['optionValueCodes'], true)) {
                $sizeM = $variant['code'];
            }
        }
        self::assertNotNull($sizeM, 'no variant in size M');

        $summaries = $this->searchAll('search_orders', [
            'containsVariant' => [$sizeM],
            'paymentState' => ['paid'],
            'shippingState' => ['ready'],
            'channel' => self::CHANNEL,
        ]);
        self::assertCount(5, $summaries);

        $orders = $this->call('get_orders', ['tokenValues' => array_column($summaries, 'tokenValue'), 'reveal' => true]);
        /** @var list<array{tokenValue: string, number: string, checkoutCompletedAt: string, customerId: string, shippingAddress: array{firstName: string, lastName: string}}> $found */
        $found = $orders['found'];
        /** @var list<array{id: string, email: string}> $customers */
        $customers = $this->call('get_customers', ['ids' => array_values(array_unique(array_column($found, 'customerId'))), 'reveal' => true])['found'];
        $emails = array_column($customers, 'email', 'id');

        $lines = [];
        foreach ($found as $order) {
            /** @var list<array{variantCode: string, quantity: int}> $items */
            $items = $this->call('list_order_collection', ['tokenValue' => $order['tokenValue'], 'kind' => 'items'])['items'];
            $quantity = 0;
            foreach ($items as $item) {
                if ($sizeM === $item['variantCode']) {
                    $quantity += $item['quantity'];
                }
            }
            self::assertNotSame('', $order['number']);
            self::assertNotSame('', $order['checkoutCompletedAt']);
            $lines[] = sprintf('%s %s <%s> × %d', $order['shippingAddress']['firstName'], $order['shippingAddress']['lastName'], $emails[$order['customerId']] ?? '?', $quantity);
        }
        sort($lines);

        self::assertSame([
            'Chloé Bernard <chloe.bernard@example.com> × 2',
            'Emma Laurent <emma.laurent@example.com> × 1',
            'Emma Laurent <emma.laurent@example.com> × 2',
            'Hugo Petit <hugo.petit@example.com> × 2',
            'Lucas Martin <lucas.martin@example.com> × 2',
        ], $lines);
    }

    /**
     * @return list<array{code: string, name: string, tracked: bool, onHand: int, optionValueCodes: list<string>}>
     */
    private function variantsOfTheProduct(): array
    {
        $codes = array_column($this->searchAll('search_product_variants', ['product' => self::PRODUCT]), 'code');
        /** @var list<array{code: string, name: string, tracked: bool, onHand: int, optionValueCodes: list<string>}> $found */
        $found = $this->call('get_product_variants', ['codes' => $codes])['found'];

        return $found;
    }
}
