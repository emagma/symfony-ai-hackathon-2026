<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

use PHPUnit\Framework\Attributes\Depends;

final class Scenario02BulkProductCreationTest extends QaScenarioTestCase
{
    private const array COLOURS = [
        'Noir' => ['color_black', 'Black'],
        'Blanc' => ['color_white', 'White'],
        'Beige' => ['color_beige', 'Beige'],
        'Bleu marine' => ['color_navy_blue', 'Navy blue'],
        'Rose' => ['color_pink', 'Pink'],
        'Bleu' => ['color_blue', 'Blue'],
        'Gris' => ['color_grey', 'Grey'],
        'Bleu clair' => ['color_light_blue', 'Light blue'],
        'Vert olive' => ['color_olive_green', 'Olive green'],
        'Jaune moutarde' => ['color_mustard_yellow', 'Mustard yellow'],
    ];

    private const array OPTION_VALUES = [
        't_shirt_size' => ['S' => 't_shirt_size_s', 'M' => 't_shirt_size_m', 'L' => 't_shirt_size_l', 'XL' => 't_shirt_size_xl', 'XXL' => 't_shirt_size_xxl'],
        'jeans_size' => ['S' => 'jeans_size_s', 'M' => 'jeans_size_m', 'L' => 'jeans_size_l', 'XL' => 'jeans_size_xl', 'XXL' => 'jeans_size_xxl'],
        'dress_size' => ['S' => 'dress_s', 'M' => 'dress_m', 'L' => 'dress_l', 'XL' => 'dress_xl', 'XXL' => 'dress_xxl'],
        'dress_height' => ['Petite' => 'dress_height_petite', 'Regular' => 'dress_height_regular', 'Tall' => 'dress_height_tall'],
    ];

    /** The supplier file names the dress_height option but gives no height. */
    private const string DRESS_HEIGHT = 'Regular';

    /** GAP: get_product_options returns `values: []` (only `valuesCount`). */
    public function testTheValuesOfTheExistingOptionsAreReadable(): void
    {
        self::assertSame(self::OPTION_VALUES, $this->optionValues(array_keys(self::OPTION_VALUES)));
    }

    #[Depends('testTheSupplierFileCreatesDisabledProductsWithTheirVariants')]
    public function testTheColourOptionIsNamedInEnglish(): void
    {
        $expected = [];
        foreach (self::COLOURS as [$code, $label]) {
            $expected[$label] = $code;
        }
        self::assertEqualsCanonicalizing($expected, $this->optionValues(['color'])['color']);
        /** @var list<array{name: ?string}> $options */
        $options = $this->call('get_product_options', ['codes' => ['color'], 'locale' => 'en_US'])['found'];
        self::assertSame('Color', $options[0]['name']);
    }

    public function testTheSupplierFileCreatesDisabledProductsWithTheirVariants(): void
    {
        $products = self::supplierFile();

        $colours = [];
        foreach (self::COLOURS as [$code, $label]) {
            $colours[$code] = $label;
        }
        self::assertBatchApplied($this->call('upsert_product_options', [
            'items' => [['code' => 'color', 'name' => 'Color', 'locale' => 'en_US', 'values' => $colours]],
            'confirm' => true,
        ]));
        $values = self::OPTION_VALUES;

        $items = [];
        foreach ($products as $product) {
            $items[] = [
                'code' => $product['code'],
                'locale' => 'en_US',
                'name' => $product['name'],
                'slug' => $product['slug'],
                'enabled' => false,
                'mainTaxonCode' => $product['mainTaxon'],
                'channelCodes' => $product['channels'],
                'optionCodes' => [...$product['options'], 'color'],
            ];
        }
        foreach (array_chunk($items, 50) as $chunk) {
            self::assertBatchApplied($this->call('upsert_products', ['items' => $chunk, 'confirm' => true]));
        }

        $attachments = [];
        foreach ($products as $product) {
            foreach ($product['taxons'] as $taxon) {
                $attachments[] = ['productCode' => $product['code'], 'taxonCode' => $taxon, 'operation' => 'add'];
            }
        }
        $attached = $this->call('update_product_taxons', ['items' => $attachments, 'confirm' => true]);
        /** @var list<array{updated: bool, reason?: string}> $results */
        $results = $attached['results'];
        foreach ($results as $result) {
            self::assertTrue($result['updated'] || 'already_attached' === ($result['reason'] ?? null), (string) json_encode($result));
        }

        $variants = [];
        foreach ($products as $product) {
            foreach ($product['sizes'] as $size) {
                $optionValues = ['color' => self::COLOURS[$product['colour']][0]];
                foreach ($product['options'] as $option) {
                    $optionValues[$option] = $values[$option]['dress_height' === $option ? self::DRESS_HEIGHT : $size];
                }
                $variants[] = [
                    'code' => self::variantCode($product['code'], $size),
                    'productCode' => $product['code'],
                    'locale' => 'en_US',
                    'name' => 'Unique' === $size ? self::COLOURS[$product['colour']][1] : $size,
                    'taxCategoryCode' => 'clothing',
                    'optionValues' => $optionValues,
                    'pricing' => array_fill_keys($product['channels'], $product['price']),
                    'tracked' => false,
                ];
            }
        }
        foreach (array_chunk($variants, 50) as $chunk) {
            self::assertBatchApplied($this->call('upsert_product_variants', ['items' => $chunk, 'confirm' => true]));
        }

        $found = $this->products(array_column($products, 'code'));
        foreach ($products as $product) {
            $created = $found[$product['code']];
            self::assertFalse($created['enabled'], $product['code'] . ' stays disabled');
            self::assertSame($product['mainTaxon'], $created['mainTaxonCode'], $product['code'] . ' main taxon');
            self::assertEqualsCanonicalizing($product['channels'], $created['channelCodes'], $product['code'] . ' channels');
            self::assertEqualsCanonicalizing([...$product['options'], 'color'], $created['optionCodes'], $product['code'] . ' options');
            self::assertSame(\count($product['sizes']), $created['variantsCount'], $product['code'] . ' variants');
            self::assertGreaterThanOrEqual(\count($product['taxons']), $created['taxonsCount'], $product['code'] . ' taxons');
        }

        $expectedVariants = array_column($variants, null, 'code');
        foreach (array_chunk(array_keys($expectedVariants), 50) as $chunk) {
            $result = $this->call('get_product_variants', ['codes' => $chunk]);
            self::assertSame([], $result['missing']);
            /** @var list<array{code: string, tracked: bool, pricing: array<string, int>, optionValueCodes: list<string>}> $read */
            $read = $result['found'];
            foreach ($read as $variant) {
                $expected = $expectedVariants[$variant['code']];
                self::assertFalse($variant['tracked'], $variant['code'] . ' has an unlimited stock');
                self::assertEquals($expected['pricing'], $variant['pricing'], $variant['code'] . ' price');
                self::assertEqualsCanonicalizing(array_values($expected['optionValues']), $variant['optionValueCodes'], $variant['code'] . ' option values');
            }
        }
    }

    #[Depends('testTheSupplierFileCreatesDisabledProductsWithTheirVariants')]
    public function testTheProductsAreEnabledOnFashionWebOnRequest(): void
    {
        $codes = array_column(self::supplierFile(), 'code');
        $items = array_map(static fn (string $code): array => ['code' => $code, 'enabled' => true], $codes);
        self::assertBatchApplied($this->call('upsert_products', ['items' => $items, 'mode' => 'update', 'confirm' => true]));

        foreach ($this->products($codes) as $product) {
            self::assertTrue($product['enabled'], $product['code'] . ' is enabled');
            self::assertContains(self::CHANNEL, $product['channelCodes'], $product['code'] . ' is on ' . self::CHANNEL);
        }
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, array{code: string, enabled: bool, mainTaxonCode: ?string, channelCodes: list<string>, optionCodes: list<string>, variantsCount: int, taxonsCount: int}>
     */
    private function products(array $codes): array
    {
        $products = [];
        foreach (array_chunk($codes, 50) as $chunk) {
            $result = $this->call('get_products', ['codes' => $chunk]);
            self::assertSame([], $result['missing']);
            /** @var list<array{code: string, enabled: bool, mainTaxonCode: ?string, channelCodes: list<string>, optionCodes: list<string>, variantsCount: int, taxonsCount: int}> $found */
            $found = $result['found'];
            foreach ($found as $product) {
                $products[$product['code']] = $product;
            }
        }

        return $products;
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, array<string, string>>
     */
    private function optionValues(array $codes): array
    {
        $result = $this->call('get_product_options', ['codes' => $codes, 'locale' => 'en_US']);
        self::assertSame([], $result['missing']);
        $values = [];
        /** @var list<array{code: string, values: list<array{code: string, value: string}>}> $options */
        $options = $result['found'];
        foreach ($options as $option) {
            $values[$option['code']] = array_column($option['values'], 'code', 'value');
        }

        return $values;
    }

    private static function variantCode(string $productCode, string $size): string
    {
        return $productCode . '_' . strtoupper($size);
    }

    /**
     * @return list<array{code: string, name: string, slug: string, mainTaxon: string, taxons: list<string>, channels: list<string>, options: list<string>, price: int, colour: string, sizes: list<string>}>
     */
    private static function supplierFile(): array
    {
        $handle = fopen(__DIR__ . '/data/fichier_fournisseur_30_produits.csv', 'r');
        self::assertIsResource($handle);
        fgetcsv($handle, escape: '');
        $products = [];
        while (false !== ($row = fgetcsv($handle, escape: ''))) {
            $list = static fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));
            $products[] = [
                'code' => (string) $row[0],
                'name' => (string) $row[1],
                'slug' => (string) $row[2],
                'mainTaxon' => (string) $row[4],
                'taxons' => $list($row[5]),
                'channels' => $list($row[6]),
                'options' => 'aucune' === $row[7] ? [] : $list($row[7]),
                'price' => (int) round((float) $row[9] * 100),
                'colour' => (string) $row[10],
                'sizes' => explode('|', (string) $row[11]),
            ];
        }
        fclose($handle);
        self::assertCount(30, $products);

        return $products;
    }
}
