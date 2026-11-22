<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

final class Scenario04BulkPriceUpdateTest extends QaScenarioTestCase
{
    public function testTheSupplierPriceListSetsThePriceAndTheOriginalPriceOfEveryVariant(): void
    {
        $expected = self::priceList();
        $items = [];
        foreach ($expected as $code => $prices) {
            $items[] = ['code' => $code, 'pricing' => $prices['pricing'], 'originalPricing' => $prices['originalPricing']];
        }

        self::assertBatchApplied($this->call('upsert_product_variants', ['items' => $items, 'mode' => 'update', 'confirm' => true]));

        foreach ($this->variants(array_keys($expected)) as $code => $variant) {
            self::assertEquals($expected[$code]['pricing'], $variant['pricing'], $code . ' price');
            self::assertEquals($expected[$code]['originalPricing'], $variant['originalPricing'], $code . ' original price');
        }
    }

    public function testEveryPriceOfATaxonRisesByTenPercentAndTheOriginalPricesStay(): void
    {
        $codes = [];
        foreach ($this->searchAll('search_products', ['taxon' => 'caps_with_pompons']) as $product) {
            /** @var list<array{code: string}> $variants */
            $variants = $this->searchAll('search_product_variants', ['product' => $product['code']]);
            array_push($codes, ...array_column($variants, 'code'));
        }
        self::assertNotEmpty($codes, 'Caps / With pompons has no variant');
        $before = $this->variants($codes);

        $items = [];
        foreach ($before as $code => $variant) {
            $items[] = ['code' => $code, 'pricing' => array_map(static fn (int $price): int => (int) round($price * 1.1), $variant['pricing'])];
        }
        self::assertBatchApplied($this->call('upsert_product_variants', ['items' => $items, 'mode' => 'update', 'confirm' => true]));

        foreach ($this->variants($codes) as $code => $variant) {
            self::assertSame(array_map(static fn (int $price): int => (int) round($price * 1.1), $before[$code]['pricing']), $variant['pricing'], $code . ' price');
            self::assertEquals($before[$code]['originalPricing'], $variant['originalPricing'], $code . ' original price');
        }
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, array{pricing: array<string, int>, originalPricing: array<string, int>}>
     */
    private function variants(array $codes): array
    {
        $variants = [];
        foreach (array_chunk($codes, 50) as $chunk) {
            $result = $this->call('get_product_variants', ['codes' => $chunk]);
            self::assertSame([], $result['missing']);
            /** @var list<array{code: string, pricing: array<string, int>, originalPricing: array<string, int>}> $found */
            $found = $result['found'];
            foreach ($found as $variant) {
                $variants[$variant['code']] = ['pricing' => $variant['pricing'], 'originalPricing' => $variant['originalPricing']];
            }
        }

        return $variants;
    }

    /**
     * @return array<string, array{pricing: array<string, int>, originalPricing: array<string, int>}>
     */
    private static function priceList(): array
    {
        $lines = file(__DIR__ . '/data/prix_caps-maj-prix.csv', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        array_shift($lines);

        $prices = [];
        foreach ($lines as $line) {
            [, , $variant, $channel, $originalPrice, $price] = str_getcsv(rtrim($line, "\r"), ';', '"', '');
            $prices[(string) $variant]['pricing'][(string) $channel] = (int) round((float) $price * 100);
            $prices[(string) $variant]['originalPricing'][(string) $channel] = (int) round((float) $originalPrice * 100);
        }

        return $prices;
    }
}
