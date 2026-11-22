<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Product;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Product\ProductUpdatePayloadBuilder;
use Emagma\McpSyliusBundle\Tool\Product\UpsertProducts;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(UpsertProducts::class)]
final class UpsertProductsTest extends McpToolTestCase
{
    /**
     * @param array<string, array<string, mixed>> $products
     * @param array<string, array<string, mixed>> $references path => body
     * @param list<list<string>>                  $fanOuts
     */
    private function clientWith(array $products, array $references, array &$fanOuts = []): MockObject
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US'], ['code' => 'fr_FR']]));
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths) use ($products, $references, &$fanOuts): array {
            $fanOuts[] = array_map(strval(...), array_values($paths));
            $out = [];
            foreach ($paths as $key => $path) {
                $path = (string) $path;
                $pool = str_contains((string) $key, "\0") ? $references : $products;
                $lookup = str_contains((string) $key, "\0") ? $path : $key;
                $out[$key] = isset($pool[$lookup]) ? ['ok' => true, 'body' => $pool[$lookup]] : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'];
            }

            return $out;
        });

        return $client;
    }

    private function tool(MockObject $client): UpsertProducts
    {
        return new UpsertProducts($client, new ReferenceResolver($client), $this->audit(), new ProductUpdatePayloadBuilder($this->adapter()), 'en_US');
    }

    public function testACreationFillsEveryLocaleAndResolvesItsReferencesOnceForTheBatch(): void
    {
        $fanOuts = [];
        $client = $this->clientWith([], [
            'taxons/mugs' => ['@id' => '/api/v2/admin/taxons/mugs'],
            'channels/FASHION_WEB' => ['@id' => '/api/v2/admin/channels/FASHION_WEB'],
            'product-options/size' => ['@id' => '/api/v2/admin/product-options/size'],
            'product-attributes/material' => ['@id' => '/api/v2/admin/product-attributes/material'],
        ], $fanOuts);
        $client->expects(self::once())->method('postMany')
            ->with(['MUG' => ['path' => 'products', 'body' => [
                'code' => 'MUG',
                'translations' => [
                    'en_US' => ['name' => 'Mug', 'slug' => 'mug', 'locale' => 'en_US'],
                    'fr_FR' => ['name' => 'Mug', 'slug' => 'mug', 'locale' => 'fr_FR'],
                ],
                'enabled' => false,
                'mainTaxon' => '/api/v2/admin/taxons/mugs',
                'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
                'options' => ['/api/v2/admin/product-options/size'],
                'attributes' => [['attribute' => '/api/v2/admin/product-attributes/material', 'localeCode' => 'en_US', 'value' => 'Ceramic']],
            ]]])
            ->willReturn(['MUG' => ['ok' => true, 'body' => ['code' => 'MUG']]]);

        $result = $this->call($this->tool($client), items: [
            ['code' => 'MUG', 'name' => 'Mug', 'slug' => 'mug', 'enabled' => false, 'mainTaxonCode' => 'mugs', 'channelCodes' => ['FASHION_WEB'], 'optionCodes' => ['size'], 'attributes' => [['code' => 'material', 'locale' => 'en_US', 'value' => 'Ceramic']]],
            ['code' => 'CAP', 'name' => 'Cap'],
            ['code' => 'HAT', 'name' => 'Hat', 'slug' => 'hat', 'mainTaxonCode' => 'nope'],
            ['code' => 'TEE', 'name' => 'Tee', 'slug' => 'tee', 'channelCodes' => ['B2B']],
        ], confirm: true);

        self::assertCount(2, $fanOuts, 'one probe, one reference fan-out');
        self::assertEqualsCanonicalizing(['taxons/mugs', 'taxons/nope', 'channels/FASHION_WEB', 'channels/B2B', 'product-options/size', 'product-attributes/material'], $fanOuts[1]);
        self::assertSame('created', $result['results']['MUG']['action']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['slug']], $result['results']['CAP']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'main_taxon_not_found', 'mainTaxonCode' => 'nope'], $result['results']['HAT']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'channel_not_found', 'channelCode' => 'B2B'], $result['results']['TEE']);
    }

    public function testAnUpdateGoesThroughThePayloadBuilderAndNullDetachesTheMainTaxon(): void
    {
        $mug = [
            'code' => 'MUG',
            'translations' => ['en_US' => ['name' => 'Mug', 'slug' => 'mug', 'locale' => 'en_US']],
            'enabled' => true,
            'mainTaxon' => '/api/v2/admin/taxons/mugs',
            'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
        ];
        $client = $this->clientWith(['MUG' => $mug, 'CAP' => ['code' => 'CAP', 'translations' => [], 'enabled' => true]], []);
        $client->expects(self::once())->method('putMany')
            ->with(['MUG' => ['path' => 'products/MUG', 'body' => array_replace($mug, [
                'translations' => ['en_US' => ['name' => 'Big mug', 'slug' => 'mug', 'locale' => 'en_US']],
                'mainTaxon' => null,
            ])]])
            ->willReturn(['MUG' => ['ok' => true, 'body' => $mug]]);

        $result = $this->call($this->tool($client), items: [
            ['code' => 'MUG', 'name' => 'Big mug', 'mainTaxonCode' => null],
            ['code' => 'CAP', 'enabled' => true],
        ], confirm: true);

        self::assertSame('updated', $result['results']['MUG']['action']);
        self::assertSame(['before' => 'mugs', 'after' => null], $result['results']['MUG']['diff']['mainTaxonCode']);
        self::assertSame('unchanged', $result['results']['CAP']['action']);
    }

    public function testNewOptionsOnAProductThatHasVariantsFailInsteadOfBeingSilentlyDropped(): void
    {
        $tee = [
            'code' => 'TEE',
            'translations' => ['en_US' => ['name' => 'Tee', 'slug' => 'tee', 'locale' => 'en_US']],
            'enabled' => true,
            'options' => ['/api/v2/admin/product-options/size'],
            'variants' => ['/api/v2/admin/product-variants/TEE_S'],
        ];
        $cap = ['code' => 'CAP', 'translations' => ['en_US' => ['name' => 'Cap', 'slug' => 'cap', 'locale' => 'en_US']], 'enabled' => true, 'options' => [], 'variants' => []];
        $client = $this->clientWith(['TEE' => $tee, 'CAP' => $cap, 'MUG' => $tee + []], [
            'product-options/size' => ['@id' => '/api/v2/admin/product-options/size'],
            'product-options/color' => ['@id' => '/api/v2/admin/product-options/color'],
        ]);
        $client->expects(self::once())->method('putMany')
            ->with(self::callback(static fn (array $requests): bool => ['CAP', 'MUG'] === array_keys($requests)))
            ->willReturn(['CAP' => ['ok' => true, 'body' => $cap], 'MUG' => ['ok' => true, 'body' => $tee]]);

        $result = $this->call($this->tool($client), items: [
            ['code' => 'TEE', 'optionCodes' => ['size', 'color']],
            ['code' => 'CAP', 'optionCodes' => ['size']],
            ['code' => 'MUG', 'optionCodes' => ['size'], 'name' => 'Mug'],
        ], confirm: true);

        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'options_locked_by_variants', 'optionCodes' => ['size'], 'variantsCount' => 1], $result['results']['TEE']);
        self::assertSame('updated', $result['results']['CAP']['action']);
        self::assertSame('updated', $result['results']['MUG']['action'], 'the same options are no change: the rest of the item goes through');
    }
}
