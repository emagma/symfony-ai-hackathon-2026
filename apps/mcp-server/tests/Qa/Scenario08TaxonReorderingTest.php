<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

final class Scenario08TaxonReorderingTest extends QaScenarioTestCase
{
    private const string TAXON = 'womens_jeans';

    private const array FIRST = ['Beachside_Bliss_Jeans', 'Beachside_Twilight_Jeans'];

    public function testOutOfStockProductsGoLastAndTwoChosenOnesFirst(): void
    {
        $attachments = $this->attachments();
        $products = array_column($attachments, 'productCode');
        foreach (self::FIRST as $code) {
            self::assertContains($code, $products, $code . ' is in ' . self::TAXON);
        }

        $outOfStock = array_values(array_filter($products, fn (string $code): bool => $this->isOutOfStock($code)));
        // The fixture's two; products other scenarios add to the taxon may join them.
        self::assertContains('Sunlit_Reverie_Jeans', $outOfStock);
        self::assertContains('Seaside_Tranquility_Jeans', $outOfStock);

        $middle = array_values(array_diff($products, self::FIRST, $outOfStock));
        $wanted = [...self::FIRST, ...$middle, ...$outOfStock];

        // First to last: each placement leaves the ones already placed where they are.
        $idOf = array_column($attachments, 'id', 'productCode');
        foreach ($wanted as $position => $code) {
            $result = $this->call('update_product_taxon_position', ['id' => $idOf[$code], 'position' => $position, 'confirm' => true]);
            // no_change: the product already sits there.
            self::assertTrue(true === ($result['updated'] ?? null) || 'no_change' === ($result['reason'] ?? null), (string) json_encode($result));
        }

        self::assertSame($wanted, array_column($this->attachments(), 'productCode'));
    }

    /**
     * @return list<array{id: string, productCode: string, position: int}>
     */
    private function attachments(): array
    {
        /** @var list<array{id: string, productCode: string, position: int}> $attachments */
        $attachments = $this->searchAll('search_product_taxons', ['taxon' => self::TAXON]);

        return $attachments;
    }

    private function isOutOfStock(string $product): bool
    {
        $codes = array_column($this->searchAll('search_product_variants', ['product' => $product]), 'code');
        if ([] === $codes) {
            return false; // nothing to sell yet, not a stock-out
        }
        /** @var list<array{tracked: bool, onHand: ?int, onHold: ?int}> $variants */
        $variants = $this->call('get_product_variants', ['codes' => $codes])['found'];
        foreach ($variants as $variant) {
            if (!$variant['tracked'] || ($variant['onHand'] ?? 0) - ($variant['onHold'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }
}
