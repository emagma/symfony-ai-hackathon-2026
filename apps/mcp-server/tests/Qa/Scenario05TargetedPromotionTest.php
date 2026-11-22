<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

/** A catalog promotion has no condition on the delivery country: "France only" takes a cart promotion. */
final class Scenario05TargetedPromotionTest extends QaScenarioTestCase
{
    private const string CATALOG_PROMOTION = 'QA_DRESSES_DEAL';

    private const string CART_PROMOTION = 'QA_DRESSES_DEAL_FR';

    public function testADressesCatalogPromotionCarriesItsDatesLabelsDescriptionsAndChannel(): void
    {
        [$start, $end] = self::period();
        self::assertBatchApplied($this->call('upsert_catalog_promotions', ['items' => [[
            'code' => self::CATALOG_PROMOTION,
            'name' => 'Dresses -10%',
            'locale' => 'en_US',
            'label' => 'Deal',
            'description' => 'Reduced for you',
            'enabled' => true,
            'startDate' => $start->format(\DATE_ATOM),
            'endDate' => $end->format(\DATE_ATOM),
            'channels' => [self::CHANNEL],
            'scopes' => [['type' => 'for_taxons', 'configuration' => ['taxons' => ['dresses']]]],
            'actions' => [['type' => 'percentage_discount', 'configuration' => ['amount' => 0.1]]],
        ]], 'confirm' => true]));
        self::assertBatchApplied($this->call('upsert_catalog_promotions', ['items' => [[
            'code' => self::CATALOG_PROMOTION,
            'locale' => 'fr_FR',
            'label' => 'promo',
            'description' => '3 jours à prix réduits',
        ]], 'confirm' => true]));

        $en = $this->catalogPromotion('en_US');
        self::assertSame(['Deal', 'Reduced for you'], [$en['label'], $en['description']]);
        self::assertTrue($en['enabled']);
        self::assertSame($start->format('Y-m-d'), self::day($en['startDate']));
        self::assertSame($end->format('Y-m-d'), self::day($en['endDate']));
        self::assertSame([self::CHANNEL], $en['channels']);
        self::assertEquals([['type' => 'for_taxons', 'configuration' => ['taxons' => ['dresses']]]], $en['scopes']);
        self::assertEquals([['type' => 'percentage_discount', 'configuration' => ['amount' => 0.1]]], $en['actions']);

        $fr = $this->catalogPromotion('fr_FR');
        self::assertSame(['promo', '3 jours à prix réduits'], [$fr['label'], $fr['description']]);
    }

    public function testTheDressesDiscountAppliesToDeliveriesInFranceOnly(): void
    {
        [$start, $end] = self::period();
        $rules = [
            ['type' => 'has_taxon', 'configuration' => ['taxons' => ['dresses']]],
            ['type' => 'shipping_country', 'configuration' => ['country' => 'FR']],
        ];
        // Sylius validates every channel of the shop, whichever the promotion runs in.
        /** @var list<string> $channels */
        $channels = array_column($this->searchAll('search_channels'), 'code');
        $perChannel = array_fill_keys($channels, ['percentage' => 0.1, 'filters' => ['taxons_filter' => ['taxons' => ['dresses']]]]);
        $actions = [['type' => 'unit_percentage_discount', 'configuration' => $perChannel]];
        self::assertBatchApplied($this->call('upsert_promotions', ['items' => [[
            'code' => self::CART_PROMOTION,
            'name' => 'Dresses -10% (France)',
            'locale' => 'en_US',
            'label' => 'Deal',
            'description' => 'Reduced for you',
            'startsAt' => $start->format(\DATE_ATOM),
            'endsAt' => $end->format(\DATE_ATOM),
            'channels' => [self::CHANNEL],
            'rules' => $rules,
            'actions' => $actions,
        ]], 'confirm' => true]));
        self::assertBatchApplied($this->call('upsert_promotions', ['items' => [[
            'code' => self::CART_PROMOTION,
            'locale' => 'fr_FR',
            'label' => 'promo',
        ]], 'confirm' => true]));

        $en = $this->cartPromotion('en_US');
        self::assertSame('Deal', $en['label']);
        self::assertSame($start->format('Y-m-d'), self::day($en['startsAt']));
        self::assertSame($end->format('Y-m-d'), self::day($en['endsAt']));
        self::assertSame([self::CHANNEL], $en['channels']);
        self::assertEqualsCanonicalizing($rules, $en['rules']);
        self::assertEquals($actions, $en['actions']);
        self::assertSame('promo', $this->cartPromotion('fr_FR')['label']);
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function period(): array
    {
        $start = new \DateTimeImmutable('first day of november this year 00:00:00');
        if ($start < new \DateTimeImmutable('today')) {
            $start = $start->modify('+1 year');
        }

        return [$start, $start->setDate((int) $start->format('Y'), 12, 15)->setTime(23, 59, 59)];
    }

    private static function day(mixed $date): string
    {
        self::assertIsString($date);

        return substr($date, 0, 10);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogPromotion(string $locale): array
    {
        /** @var array{found: list<array<string, mixed>>, missing: list<string>} $result */
        $result = $this->call('get_catalog_promotions', ['codes' => [self::CATALOG_PROMOTION], 'locale' => $locale]);
        self::assertSame([], $result['missing']);

        return $result['found'][0];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartPromotion(string $locale): array
    {
        /** @var array{found: list<array<string, mixed>>, missing: list<string>} $result */
        $result = $this->call('get_promotions', ['codes' => [self::CART_PROMOTION], 'locale' => $locale]);
        self::assertSame([], $result['missing']);

        return $result['found'][0];
    }
}
