<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

/** The fixes are undone at the end of each test, so the audit finds the same products on a rerun. */
final class Scenario03CatalogQualityTest extends QaScenarioTestCase
{
    private const array WITHOUT_SHORT_DESCRIPTION = ['Aurora_Breeze_T_Shirt', 'Nebula_Glow_T_Shirt', 'Orbit_Pulse_T_Shirt'];

    private const array UPPERCASE = ['Galaxy_Ride_T_Shirt', 'Quasar_Bloom_T_Shirt'];

    private const string IMAGE = 'Stellar_Drift_T_Shirt_Emagma_1-1.jpg';

    public function testTheAuditFindsTheProductsWithoutImageOrShortDescriptionOrWithAnUppercaseName(): void
    {
        $withoutImage = [];
        $withoutShortDescription = [];
        $uppercase = [];
        foreach ($this->allProducts() as $product) {
            if (0 === $product['imagesCount']) {
                $withoutImage[] = $product['code'];
            }
            if ('' === trim((string) $product['shortDescription'])) {
                $withoutShortDescription[] = $product['code'];
            }
            if (self::isUppercase((string) $product['name'])) {
                $uppercase[] = $product['code'];
            }
        }

        // Subsets: other scenarios may add products of their own.
        foreach ([...self::WITHOUT_SHORT_DESCRIPTION, ...self::UPPERCASE] as $code) {
            self::assertContains($code, $withoutImage, $code . ' has no image');
        }
        foreach (self::WITHOUT_SHORT_DESCRIPTION as $code) {
            self::assertContains($code, $withoutShortDescription, $code . ' has no short description');
        }
        foreach (self::UPPERCASE as $code) {
            self::assertContains($code, $uppercase, $code . ' has an uppercase name');
        }
        self::assertSame([], array_diff($withoutShortDescription, self::WITHOUT_SHORT_DESCRIPTION, self::createdByScenario2($withoutShortDescription)));
        self::assertSame([], array_diff($uppercase, self::UPPERCASE));
    }

    public function testTheNamesAndShortDescriptionsAreFixed(): void
    {
        $before = $this->products([...self::WITHOUT_SHORT_DESCRIPTION, ...self::UPPERCASE]);

        $items = [];
        foreach (self::WITHOUT_SHORT_DESCRIPTION as $code) {
            $items[] = ['code' => $code, 'locale' => 'en_US', 'shortDescription' => 'A soft cotton tee for every day.'];
        }
        foreach (self::UPPERCASE as $code) {
            $items[] = ['code' => $code, 'locale' => 'en_US', 'name' => ucwords(mb_strtolower((string) $before[$code]['name']), ' -')];
        }
        self::assertBatchApplied($this->call('upsert_products', ['items' => $items, 'mode' => 'update', 'confirm' => true]));

        $after = $this->products([...self::WITHOUT_SHORT_DESCRIPTION, ...self::UPPERCASE]);
        foreach (self::WITHOUT_SHORT_DESCRIPTION as $code) {
            self::assertSame('A soft cotton tee for every day.', $after[$code]['shortDescription']);
        }
        self::assertSame('Galaxy Ride T-Shirt', $after['Galaxy_Ride_T_Shirt']['name']);
        self::assertSame('Quasar Bloom T-Shirt', $after['Quasar_Bloom_T_Shirt']['name']);

        $undo = [];
        foreach (self::WITHOUT_SHORT_DESCRIPTION as $code) {
            $undo[] = ['code' => $code, 'locale' => 'en_US', 'shortDescription' => ''];
        }
        foreach (self::UPPERCASE as $code) {
            $undo[] = ['code' => $code, 'locale' => 'en_US', 'name' => (string) $before[$code]['name']];
        }
        self::assertBatchApplied($this->call('upsert_products', ['items' => $undo, 'mode' => 'update', 'confirm' => true]));
    }

    public function testAMissingImageIsUploaded(): void
    {
        $code = self::WITHOUT_SHORT_DESCRIPTION[0];
        $id = $this->upload($code);

        try {
            self::assertSame(1, $this->products([$code])[$code]['imagesCount']);
        } finally {
            $this->deleteImage($code, $id);
        }
    }

    /** GAP: a Sylius product image has no alt text, and no image tool takes one. */
    public function testTheUploadedImageGetsItsAltText(): void
    {
        $code = self::WITHOUT_SHORT_DESCRIPTION[0];
        $product = $this->products([$code])[$code];
        /** @var list<array{name: ?string}> $taxons */
        $taxons = $this->call('get_taxons', ['codes' => [$product['mainTaxonCode']], 'locale' => 'en_US'])['found'];
        $alt = sprintf('%s - %s - %s', $product['name'], $taxons[0]['name'], self::IMAGE);

        $id = $this->upload($code);

        try {
            $this->call('update_product_image', ['code' => $code, 'id' => $id, 'alt' => $alt, 'confirm' => true]);
            $images = $this->searchAll('search_product_images', ['code' => $code]);
            self::assertSame([$alt], array_column($images, 'alt'));
        } finally {
            $this->deleteImage($code, $id);
        }
    }

    private function upload(string $code): int
    {
        $content = file_get_contents(__DIR__ . '/data/' . self::IMAGE);
        self::assertIsString($content);
        $created = $this->call('create_product_image', [
            'code' => $code,
            'base64Content' => base64_encode($content),
            'filename' => self::IMAGE,
            'mimeType' => 'image/jpeg',
            'confirm' => true,
        ]);
        self::assertTrue($created['ok'] ?? false, (string) json_encode($created));
        self::assertIsInt($created['id']);

        return $created['id'];
    }

    private function deleteImage(string $code, int $id): void
    {
        $deleted = $this->call('delete_product_image', ['code' => $code, 'id' => $id, 'confirm' => true]);
        self::assertTrue($deleted['ok'] ?? false, (string) json_encode($deleted));
    }

    /**
     * @return list<array{code: string, name: ?string, shortDescription: ?string, imagesCount: int}>
     */
    private function allProducts(): array
    {
        /** @var list<string> $codes */
        $codes = array_column($this->searchAll('search_products'), 'code');

        return array_values($this->products($codes));
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, array{code: string, name: ?string, shortDescription: ?string, imagesCount: int, mainTaxonCode: ?string}>
     */
    private function products(array $codes): array
    {
        $products = [];
        foreach (array_chunk($codes, 50) as $chunk) {
            $result = $this->call('get_products', ['codes' => $chunk, 'locale' => 'en_US']);
            self::assertSame([], $result['missing']);
            /** @var list<array{code: string, name: ?string, shortDescription: ?string, imagesCount: int, mainTaxonCode: ?string}> $found */
            $found = $result['found'];
            foreach ($found as $product) {
                $products[$product['code']] = $product;
            }
        }

        return $products;
    }

    /**
     * The products scenario 2 creates from the supplier file carry no short description.
     *
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private static function createdByScenario2(array $codes): array
    {
        return array_values(array_filter($codes, static fn (string $code): bool => str_ends_with($code, '_Emagma') || str_ends_with($code, '_Winter_collection')));
    }

    private static function isUppercase(string $name): bool
    {
        return 1 === preg_match('/\p{L}/u', $name) && mb_strtoupper($name) === $name;
    }
}
