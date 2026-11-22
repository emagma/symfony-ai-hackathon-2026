<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

/** FASHION_WEB sells in USD: the scenario's "25 €" is 2500 minor units of the channel currency. */
final class Scenario06CouponOperationTest extends QaScenarioTestCase
{
    private const string BF25_PROMOTION = 'QA_BLACK_FRIDAY_BF25';

    private const string INFLUENCER_PROMOTION = 'QA_BLACK_FRIDAY_INFLUENCERS';

    public function testTheBf25CouponTakes25OffFrom100OnceACustomerDuringBlackFriday(): void
    {
        [$start, $end] = self::blackFriday();
        $rules = [['type' => 'item_total', 'configuration' => $this->perChannel(['amount' => 10000])]];
        $actions = [['type' => 'order_fixed_discount', 'configuration' => $this->perChannel(['amount' => 2500])]];
        self::assertBatchApplied($this->call('upsert_promotions', ['items' => [[
            'code' => self::BF25_PROMOTION,
            'name' => 'Black Friday BF25',
            'label' => 'Black Friday',
            'couponBased' => true,
            'startsAt' => $start->format(\DATE_ATOM),
            'endsAt' => $end->format(\DATE_ATOM),
            'channels' => [self::CHANNEL],
            'rules' => $rules,
            'actions' => $actions,
        ]], 'confirm' => true]));
        self::assertBatchApplied($this->call('upsert_promotion_coupons', [
            'promotionCode' => self::BF25_PROMOTION,
            'items' => [['code' => 'BF25', 'perCustomerUsageLimit' => 1, 'expiresAt' => $end->format(\DATE_ATOM)]],
            'confirm' => true,
        ]));

        $promotion = $this->promotion(self::BF25_PROMOTION);
        self::assertTrue($promotion['couponBased']);
        self::assertSame($start->format('Y-m-d'), self::day($promotion['startsAt']));
        self::assertSame($end->format('Y-m-d'), self::day($promotion['endsAt']));
        self::assertEquals($rules, $promotion['rules']);
        self::assertEquals($actions, $promotion['actions']);

        $coupons = $this->searchAll('search_promotion_coupons', ['promotionCode' => self::BF25_PROMOTION, 'code' => 'BF25']);
        self::assertCount(1, $coupons);
        self::assertSame(1, $coupons[0]['perCustomerUsageLimit']);
        self::assertSame($end->format('Y-m-d'), self::day($coupons[0]['expiresAt']));
    }

    public function testTenInfluencerCodesThenNinetyMoreMakeAHundredUniqueCodes(): void
    {
        [$start, $end] = self::blackFriday();
        self::assertBatchApplied($this->call('upsert_promotions', ['items' => [[
            'code' => self::INFLUENCER_PROMOTION,
            'name' => 'Black Friday influencers -15%',
            'label' => 'Black Friday',
            'couponBased' => true,
            'startsAt' => $start->format(\DATE_ATOM),
            'endsAt' => $end->format(\DATE_ATOM),
            'channels' => [self::CHANNEL],
            'actions' => [['type' => 'order_percentage_discount', 'configuration' => ['percentage' => 0.15]]],
        ]], 'confirm' => true]));
        // Idempotent across runs: what this run adds, not what exists.
        $before = $this->couponCodes();

        foreach ([10, 90] as $amount) {
            $generated = $this->call('generate_promotion_coupons', [
                'promotionCode' => self::INFLUENCER_PROMOTION,
                'amount' => $amount,
                'codeLength' => 8,
                'prefix' => 'INFL-',
                'usageLimit' => 1,
                'expiresAt' => $end->format(\DATE_ATOM),
                'confirm' => true,
            ]);
            self::assertTrue($generated['generated'] ?? false, (string) json_encode($generated));
        }

        $after = $this->couponCodes();
        $added = array_values(array_diff($after, $before));
        self::assertCount(100, $added);
        self::assertCount(100, array_unique($added));
        self::assertSame(\count($after), $this->promotion(self::INFLUENCER_PROMOTION)['couponCount']);
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function blackFriday(): array
    {
        $start = (new \DateTimeImmutable('today'))->setDate((int) date('Y'), 11, 27);
        if ($start < new \DateTimeImmutable('today')) {
            $start = $start->modify('+1 year');
        }

        return [$start, $start->modify('+4 days')->setTime(23, 59, 59)];
    }

    /**
     * Sylius validates every channel of the shop, whichever the promotion runs in.
     *
     * @param array<string, mixed> $configuration
     *
     * @return array<string, array<string, mixed>>
     */
    private function perChannel(array $configuration): array
    {
        /** @var list<string> $channels */
        $channels = array_column($this->searchAll('search_channels'), 'code');

        return array_fill_keys($channels, $configuration);
    }

    /**
     * @return list<string>
     */
    private function couponCodes(): array
    {
        /** @var list<array{code: string}> $coupons */
        $coupons = $this->searchAll('search_promotion_coupons', ['promotionCode' => self::INFLUENCER_PROMOTION]);

        return array_column($coupons, 'code');
    }

    /**
     * @return array<string, mixed>
     */
    private function promotion(string $code): array
    {
        return $this->first('get_promotions', ['codes' => [$code]]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function first(string $tool, array $arguments): array
    {
        /** @var array{found: list<array<string, mixed>>, missing: list<string>} $result */
        $result = $this->call($tool, $arguments);
        self::assertSame([], $result['missing']);

        return $result['found'][0];
    }

    private static function day(mixed $date): string
    {
        self::assertIsString($date);

        return substr($date, 0, 10);
    }
}
