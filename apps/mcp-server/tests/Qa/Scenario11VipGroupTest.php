<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

final class Scenario11VipGroupTest extends QaScenarioTestCase
{
    private const string GROUP = 'qa_vip';

    private const string PROMOTION = 'QA_VIP_15';

    public function testTheTenBestCustomersFormTheVipGroupAndOnlyItGetsFifteenPercentOff(): void
    {
        // get_customer_statistics counts only fulfilled (paid AND shipped) orders, as Sylius does.
        /** @var list<array{customerId: string, total: int}> $orders */
        $orders = $this->searchAll('search_orders', ['channel' => self::CHANNEL, 'paymentState' => ['paid']]);
        $spent = [];
        foreach ($orders as $order) {
            $spent[$order['customerId']] = ($spent[$order['customerId']] ?? 0) + $order['total'];
        }
        self::assertGreaterThanOrEqual(10, \count($spent), 'fewer than 10 customers paid an order in the channel');
        arsort($spent);
        // array_keys() gives numeric ids back as integers; the tools take strings.
        $best = array_map(static fn (int|string $id): string => (string) $id, \array_slice(array_keys($spent), 0, 10));

        self::assertBatchApplied($this->call('upsert_customer_groups', ['items' => [['code' => self::GROUP, 'name' => 'VIP']], 'confirm' => true]));

        $ids = array_values(array_unique([...$best, ...$this->groupMembers()]));
        /** @var array{found: list<array{id: string, email: string}>} $customers */
        $customers = $this->call('get_customers', ['ids' => $ids, 'reveal' => true]);
        $emails = array_column($customers['found'], 'email', 'id');
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['email' => $emails[$id], 'groupCode' => \in_array($id, $best, true) ? self::GROUP : null];
        }
        self::assertBatchApplied($this->call('upsert_customers', ['items' => $items, 'confirm' => true]));

        self::assertBatchApplied($this->call('upsert_promotions', ['items' => [[
            'code' => self::PROMOTION,
            'name' => 'VIP -15%',
            'label' => 'VIP',
            'channels' => [self::CHANNEL],
            'rules' => [['type' => 'customer_group', 'configuration' => ['group_code' => self::GROUP]]],
            'actions' => [['type' => 'order_percentage_discount', 'configuration' => ['percentage' => 0.15]]],
        ]], 'confirm' => true]));

        self::assertEqualsCanonicalizing($best, $this->groupMembers());

        /** @var array{found: list<array<string, mixed>>} $promotions */
        $promotions = $this->call('get_promotions', ['codes' => [self::PROMOTION]]);
        $promotion = $promotions['found'][0];
        self::assertFalse($promotion['couponBased']);
        self::assertSame([self::CHANNEL], $promotion['channels']);
        self::assertEquals([['type' => 'customer_group', 'configuration' => ['group_code' => self::GROUP]]], $promotion['rules']);
        self::assertEquals([['type' => 'order_percentage_discount', 'configuration' => ['percentage' => 0.15]]], $promotion['actions']);
    }

    public function testTheStatisticsOfACustomerAreReadPerChannel(): void
    {
        /** @var array{items: list<array{customerId: string}>} $orders */
        $orders = $this->call('search_orders', ['channel' => self::CHANNEL, 'paymentState' => ['paid'], 'itemsPerPage' => 1]);
        /** @var array{perChannel: list<array{channelCode: ?string}>} $statistics */
        $statistics = $this->call('get_customer_statistics', ['id' => $orders['items'][0]['customerId']]);

        // GAP: in JSON-LD perChannelsStatistics is a hydra:Collection, not a list: the tool reports one row of nulls.
        foreach ($statistics['perChannel'] as $row) {
            self::assertIsString($row['channelCode'], (string) json_encode($statistics));
        }
    }

    /**
     * @return list<string> the ids of the group's customers
     */
    private function groupMembers(): array
    {
        /** @var list<array{id: string}> $members */
        $members = $this->searchAll('search_customers', ['groupCode' => self::GROUP]);

        return array_column($members, 'id');
    }
}
