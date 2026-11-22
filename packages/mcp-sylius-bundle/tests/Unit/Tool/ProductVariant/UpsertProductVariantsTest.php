<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductVariant;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductVariant\UpsertProductVariants;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertProductVariants::class)]
final class UpsertProductVariantsTest extends McpToolTestCase
{
    /**
     * @param array<string, array<string, mixed>> $variants
     * @param array<string, array<string, mixed>> $references path => body
     */
    private function clientWith(array $variants, array $references, array &$fanOuts = []): \PHPUnit\Framework\MockObject\MockObject
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths) use ($variants, $references, &$fanOuts): array {
            $fanOuts[] = array_map(strval(...), array_values($paths));
            $out = [];
            foreach ($paths as $key => $path) {
                $path = (string) $path;
                if (str_contains((string) $key, "\0")) {
                    $out[$key] = isset($references[$path]) ? ['ok' => true, 'body' => $references[$path]] : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'];
                } else {
                    $out[$key] = isset($variants[$key]) ? ['ok' => true, 'body' => $variants[$key]] : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'];
                }
            }

            return $out;
        });

        return $client;
    }

    public function testEveryReferenceIsResolvedInOneFanOutAndAnUnknownOneFailsItsItemOnly(): void
    {
        $fanOuts = [];
        $client = $this->clientWith([], [
            'products/MUG' => ['@id' => '/api/v2/admin/products/MUG'],
            'tax-categories/standard' => ['@id' => '/api/v2/admin/tax-categories/standard'],
            'product-options/size/values/m' => ['@id' => '/api/v2/admin/product-options/size/values/m'],
        ], $fanOuts);
        $client->expects(self::once())->method('postMany')
            ->with(['MUG_M' => ['path' => 'product-variants', 'body' => [
                'code' => 'MUG_M',
                'product' => '/api/v2/admin/products/MUG',
                'enabled' => true,
                'taxCategory' => '/api/v2/admin/tax-categories/standard',
                'translations' => ['en_US' => ['name' => 'Mug M']],
                'optionValues' => ['/api/v2/admin/product-options/size/values/m'],
                'channelPricings' => ['FASHION_WEB' => ['channelCode' => 'FASHION_WEB', 'price' => 1200]],
                'tracked' => true,
                'onHand' => 5,
            ]]])
            ->willReturn(['MUG_M' => ['ok' => true, 'body' => ['code' => 'MUG_M']]]);

        $result = $this->call(new UpsertProductVariants($client, new ReferenceResolver($client), $this->audit(), 'en_US'), items: [
            ['code' => 'MUG_M', 'productCode' => 'MUG', 'name' => 'Mug M', 'taxCategoryCode' => 'standard', 'optionValues' => ['size' => 'm'], 'pricing' => ['FASHION_WEB' => 1200], 'tracked' => true, 'onHand' => 5],
            ['code' => 'MUG_L', 'productCode' => 'MUG', 'optionValues' => ['size' => 'l']],
            ['code' => 'CAP', 'productCode' => 'NOPE'],
            ['code' => 'MUG_XL', 'productCode' => 'MUG', 'taxCategoryCode' => 'reduced'],
            ['code' => 'ORPHAN'],
        ], confirm: true);

        self::assertCount(2, $fanOuts, 'one probe, one reference fan-out');
        self::assertEqualsCanonicalizing(
            ['products/MUG', 'products/NOPE', 'tax-categories/standard', 'tax-categories/reduced', 'product-options/size/values/m', 'product-options/size/values/l'],
            $fanOuts[1],
        );
        self::assertSame('created', $result['results']['MUG_M']['action']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'option_value_not_found', 'optionCode' => 'size', 'valueCode' => 'l'], $result['results']['MUG_L']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'product_not_found', 'productCode' => 'NOPE'], $result['results']['CAP']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'tax_category_not_found', 'taxCategoryCode' => 'reduced'], $result['results']['MUG_XL']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['productCode']], $result['results']['ORPHAN']);
        self::assertSame(4, $result['failed']);
    }

    public function testAnUpdateMergesPricingReplacesOptionValuesAndKeepsItsProduct(): void
    {
        $current = [
            'code' => 'MUG_M',
            'product' => '/api/v2/admin/products/MUG',
            'enabled' => true,
            'tracked' => false,
            'translations' => ['en_US' => ['name' => 'Mug M']],
            'optionValues' => ['/api/v2/admin/product-options/size/values/m'],
            'channelPricings' => ['FASHION_WEB' => ['channelCode' => 'FASHION_WEB', 'price' => 1200], 'B2B' => ['channelCode' => 'B2B', 'price' => 900]],
        ];
        $client = $this->clientWith(['MUG_M' => $current, 'MUG_L' => $current + []], [
            'product-options/size/values/l' => ['@id' => '/api/v2/admin/product-options/size/values/l'],
        ]);
        $client->expects(self::once())->method('putMany')
            ->with(['MUG_M' => ['path' => 'product-variants/MUG_M', 'body' => array_replace($current, [
                'enabled' => false,
                'optionValues' => ['/api/v2/admin/product-options/size/values/l'],
                'channelPricings' => ['FASHION_WEB' => ['channelCode' => 'FASHION_WEB', 'price' => 1500], 'B2B' => ['channelCode' => 'B2B', 'price' => 900]],
            ])]])
            ->willReturn(['MUG_M' => ['ok' => true, 'body' => $current]]);

        $result = $this->call(new UpsertProductVariants($client, new ReferenceResolver($client), $this->audit(), 'en_US'), items: [
            ['code' => 'MUG_M', 'enabled' => false, 'optionValues' => ['size' => 'l'], 'pricing' => ['FASHION_WEB' => 1500]],
            ['code' => 'MUG_L', 'productCode' => 'OTHER'],
        ], confirm: true);

        self::assertSame(['enabled', 'optionValues', 'pricing'], array_keys($result['results']['MUG_M']['diff']));
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'product_immutable', 'productCode' => 'MUG'], $result['results']['MUG_L']);
    }

    public function testAPriceUpdateKeepsEachStoredPricingWholeAndSetsTheOriginalPrice(): void
    {
        $current = [
            'code' => 'MUG_M',
            'product' => '/api/v2/admin/products/MUG',
            'enabled' => true,
            'channelPricings' => [
                'FASHION_WEB' => ['@id' => '/api/v2/admin/product-variants/MUG_M/channel-pricings/FASHION_WEB', 'price' => 1200, 'originalPrice' => 1500, 'minimumPrice' => 100],
                'B2B' => ['@id' => '/api/v2/admin/product-variants/MUG_M/channel-pricings/B2B', 'price' => 900, 'originalPrice' => 1000, 'minimumPrice' => 0],
            ],
        ];
        $client = $this->clientWith(['MUG_M' => $current], []);
        $client->expects(self::once())->method('putMany')
            ->with(['MUG_M' => ['path' => 'product-variants/MUG_M', 'body' => array_replace($current, [
                'channelPricings' => [
                    'FASHION_WEB' => ['@id' => '/api/v2/admin/product-variants/MUG_M/channel-pricings/FASHION_WEB', 'price' => 1320, 'originalPrice' => 1500, 'minimumPrice' => 100],
                    'B2B' => ['@id' => '/api/v2/admin/product-variants/MUG_M/channel-pricings/B2B', 'price' => 900, 'originalPrice' => null, 'minimumPrice' => 0],
                    'NEW' => ['channelCode' => 'NEW', 'price' => 500, 'originalPrice' => 700],
                ],
            ])]])
            ->willReturn(['MUG_M' => ['ok' => true, 'body' => $current]]);

        $result = $this->call(new UpsertProductVariants($client, new ReferenceResolver($client), $this->audit(), 'en_US'), items: [
            ['code' => 'MUG_M', 'pricing' => ['FASHION_WEB' => 1320, 'NEW' => 500], 'originalPricing' => ['B2B' => null, 'NEW' => 700]],
        ], confirm: true);

        self::assertSame(['pricing', 'originalPricing'], array_keys($result['results']['MUG_M']['diff']));
        self::assertSame(['FASHION_WEB' => 1500, 'B2B' => 1000], $result['results']['MUG_M']['diff']['originalPricing']['before']);
    }

    public function testAnOriginalPriceThatIsNotAMapOfIntegersOrNullRejectsTheBatch(): void
    {
        $client = $this->clientWith([], []);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertProductVariants($client, new ReferenceResolver($client), $this->audit(), 'en_US'), items: [
            ['code' => 'MUG_M', 'originalPricing' => ['FASHION_WEB' => '15.00']],
        ], confirm: true);

        self::assertSame('items_invalid', $result['reason']);
        self::assertSame('originalPricing is a {channelCode: originalPrice} map of integers (minor units) or null', $result['errors'][0]['message']);
    }

    public function testTheSamePriceAndAnIntegerWeightAreNotChanges(): void
    {
        $current = ['code' => 'MUG_M', 'product' => '/api/v2/admin/products/MUG', 'enabled' => true, 'weight' => 2.0, 'channelPricings' => ['FASHION_WEB' => ['channelCode' => 'FASHION_WEB', 'price' => 1200]]];
        $client = $this->clientWith(['MUG_M' => $current], []);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertProductVariants($client, new ReferenceResolver($client), $this->audit(), 'en_US'), items: [
            ['code' => 'MUG_M', 'productCode' => 'MUG', 'weight' => 2, 'pricing' => ['FASHION_WEB' => 1200]],
        ]);

        self::assertSame('unchanged', $result['results']['MUG_M']['action']);
    }
}
