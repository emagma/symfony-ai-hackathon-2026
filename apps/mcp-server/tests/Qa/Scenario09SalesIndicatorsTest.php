<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

/** No statistics tool: the model pages through search_orders and computes. */
final class Scenario09SalesIndicatorsTest extends QaScenarioTestCase
{
    /** A cancelled order is not a sale. */
    private const array PLACED = ['new', 'fulfilled'];

    public function testTheOrdersOfTheLast30DaysAreCountedPerHourOfTheDay(): void
    {
        $from = (new \DateTimeImmutable('-30 days', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        $orders = $this->orders(['completedFrom' => $from]);
        self::assertNotEmpty($orders, 'orders in the last 30 days');

        $perHour = array_fill(0, 24, 0);
        foreach ($orders as $order) {
            $completedAt = new \DateTimeImmutable($order['checkoutCompletedAt'], new \DateTimeZone('UTC'));
            self::assertGreaterThanOrEqual(new \DateTimeImmutable($from), $completedAt, $order['number'] . ' is in the range');
            ++$perHour[(int) $completedAt->format('G')];
        }
        self::assertSame(\count($orders), array_sum($perHour));
        // The 5 QA orders of scenario 1 were completed at 10:30.
        self::assertGreaterThanOrEqual(5, $perHour[10]);

        $split = 0;
        foreach (array_unique(array_column($orders, 'paymentState')) as $paymentState) {
            $split += \count($this->orders(['completedFrom' => $from, 'paymentState' => [$paymentState]]));
        }
        self::assertSame(\count($orders), $split);
    }

    public function testTheRevenueOfTheLastSixMonthsIsComparedWithTheSixBefore(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sixMonthsAgo = $now->modify('-6 months');
        $recent = $this->orders(['completedFrom' => $sixMonthsAgo->format(\DATE_ATOM), 'completedTo' => $now->format(\DATE_ATOM)]);
        $previous = $this->orders([
            'completedFrom' => $sixMonthsAgo->modify('-6 months')->format(\DATE_ATOM),
            'completedTo' => $sixMonthsAgo->modify('-1 second')->format(\DATE_ATOM),
        ]);
        self::assertNotEmpty($recent, 'orders in the last 6 months');
        self::assertNotEmpty($previous, 'orders in the 6 months before');
        self::assertSame([], array_intersect(array_column($recent, 'tokenValue'), array_column($previous, 'tokenValue')), 'the two periods do not overlap');

        foreach ([$recent, $previous] as $orders) {
            $revenue = $this->revenue($orders);
            self::assertGreaterThan(0, $revenue);
            self::assertGreaterThan(0, intdiv($revenue, \count($orders)), 'average basket');
        }

        $totals = array_column($recent, 'total');
        sort($totals);
        $threshold = $totals[intdiv(\count($totals), 2)];
        $window = ['completedFrom' => $sixMonthsAgo->format(\DATE_ATOM), 'completedTo' => $now->format(\DATE_ATOM), 'channel' => self::CHANNEL];
        $low = $this->orders($window + ['totalMax' => $threshold]);
        $high = $this->orders($window + ['totalMin' => $threshold + 1]);
        $inChannel = array_values(array_filter($recent, static fn (array $order): bool => self::CHANNEL === $order['channelCode']));
        self::assertSame(\count($inChannel), \count($low) + \count($high));
        self::assertSame($this->revenue($inChannel), $this->revenue($low) + $this->revenue($high));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<array{tokenValue: string, number: string, paymentState: string, total: int, currencyCode: string, checkoutCompletedAt: string, channelCode: string}>
     */
    private function orders(array $filters): array
    {
        $first = $this->call('search_orders', $filters + ['state' => self::PLACED, 'itemsPerPage' => 1]);
        /** @var list<array{tokenValue: string, number: string, paymentState: string, total: int, currencyCode: string, checkoutCompletedAt: string, channelCode: string}> $orders */
        $orders = $this->searchAll('search_orders', $filters + ['state' => self::PLACED]);

        self::assertSame($first['total'], \count($orders), 'every page read');
        self::assertSame(\count($orders), \count(array_unique(array_column($orders, 'tokenValue'))), 'no order twice');

        return $orders;
    }

    /**
     * @param list<array{total: int, currencyCode: string}> $orders
     */
    private function revenue(array $orders): int
    {
        self::assertLessThanOrEqual(1, \count(array_unique(array_column($orders, 'currencyCode'))), 'one currency');

        return array_sum(array_column($orders, 'total'));
    }
}
