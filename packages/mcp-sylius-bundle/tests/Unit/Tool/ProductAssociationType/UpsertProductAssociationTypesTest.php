<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAssociationType;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAssociationType\UpsertProductAssociationTypes;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertProductAssociationTypes::class)]
final class UpsertProductAssociationTypesTest extends McpToolTestCase
{
    public function testACreationFillsEveryEnabledLocaleReadOnceForTheBatch(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecoded')
            ->with('locales', ['itemsPerPage' => 100])
            ->willReturn($this->collection([['code' => 'en_US'], ['code' => 'fr_FR']]));
        $client->method('getDecodedMany')->willReturn([
            'similar' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'upsell' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with([
                'similar' => ['path' => 'product-association-types', 'body' => ['code' => 'similar', 'translations' => [
                    'en_US' => ['name' => 'Similar products', 'locale' => 'en_US'],
                    'fr_FR' => ['name' => 'Produits similaires', 'locale' => 'fr_FR'],
                ]]],
                'upsell' => ['path' => 'product-association-types', 'body' => ['code' => 'upsell', 'translations' => [
                    'en_US' => ['name' => 'Upsell', 'locale' => 'en_US'],
                    'fr_FR' => ['name' => 'Upsell', 'locale' => 'fr_FR'],
                ]]],
            ])
            ->willReturn(['similar' => ['ok' => true, 'body' => ['code' => 'similar']], 'upsell' => ['ok' => true, 'body' => ['code' => 'upsell']]]);

        $result = $this->call(new UpsertProductAssociationTypes($client, $this->audit(), 'en_US'), items: [
            ['code' => 'similar', 'name' => 'Similar products', 'names' => ['fr_FR' => 'Produits similaires']],
            ['code' => 'upsell', 'name' => 'Upsell'],
        ], confirm: true);

        self::assertSame(2, $result['created']);
        self::assertSame(['before' => null, 'after' => 'Produits similaires'], $result['results']['similar']['diff']['fr_FR.name']);
    }

    public function testAnUpdateOverlaysTheNamedLocalesAndKeepsTheTranslationIds(): void
    {
        $current = ['code' => 'similar', 'translations' => [
            'en_US' => ['@id' => '/api/v2/admin/product-association-type-translations/1', 'name' => 'Similar', 'locale' => 'en_US'],
        ]];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US'], ['code' => 'fr_FR']]));
        $client->method('getDecodedMany')->willReturn(['similar' => ['ok' => true, 'body' => $current]]);
        $client->expects(self::once())->method('putMany')
            ->with(['similar' => ['path' => 'product-association-types/similar', 'body' => ['code' => 'similar', 'translations' => [
                'en_US' => ['@id' => '/api/v2/admin/product-association-type-translations/1', 'name' => 'Similar products', 'locale' => 'en_US'],
                'fr_FR' => ['name' => 'Produits similaires', 'locale' => 'fr_FR'],
            ]]]])
            ->willReturn(['similar' => ['ok' => true, 'body' => $current]]);

        $result = $this->call(new UpsertProductAssociationTypes($client, $this->audit(), 'en_US'), items: [
            ['code' => 'similar', 'name' => 'Similar products', 'names' => ['fr_FR' => 'Produits similaires']],
        ], confirm: true);

        self::assertSame('updated', $result['results']['similar']['action']);
        self::assertSame(['en_US.name', 'fr_FR.name'], array_keys($result['results']['similar']['diff']));
    }

    public function testTheSameNameIsUnchanged(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn(['similar' => ['ok' => true, 'body' => ['code' => 'similar', 'translations' => ['en_US' => ['name' => 'Similar']]]]]);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertProductAssociationTypes($client, $this->audit(), 'en_US'), items: [['code' => 'similar', 'name' => 'Similar']]);

        self::assertSame('unchanged', $result['results']['similar']['action']);
    }
}
