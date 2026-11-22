<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\CatalogPromotion;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\CatalogPromotion\DeleteCatalogPromotions;
use Emagma\McpSyliusBundle\Tool\CatalogPromotion\UpsertCatalogPromotions;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertCatalogPromotions::class)]
final class UpsertCatalogPromotionsTest extends McpToolTestCase
{
    public function testAProcessingPromotionIsRefusedBeforeThePlanAndA400AfterwardsReadsTheSame(): void
    {
        $winter = ['code' => 'winter', 'name' => 'Winter', 'state' => 'active', 'enabled' => false, 'exclusive' => false, 'channels' => [], 'scopes' => [], 'actions' => [], 'translations' => []];
        $busy = ['code' => 'busy', 'name' => 'Busy', 'state' => 'processing', 'enabled' => true];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn([
            'winter' => ['ok' => true, 'body' => $winter],
            'busy' => ['ok' => true, 'body' => $busy],
            'spring' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['spring' => ['path' => 'catalog-promotions', 'body' => [
                'code' => 'spring', 'name' => 'Spring', 'enabled' => false, 'exclusive' => false, 'channels' => ['/api/v2/admin/channels/WEB'],
                'scopes' => [['type' => 'for_taxons', 'configuration' => ['taxons' => ['mugs']]]],
                'actions' => [['type' => 'percentage_discount', 'configuration' => ['amount' => 0.2]]],
                'translations' => ['en_US' => ['label' => 'Spring', 'locale' => 'en_US']],
            ]]])
            ->willReturn(['spring' => ['ok' => true, 'body' => ['code' => 'spring']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['winter' => ['path' => 'catalog-promotions/winter', 'body' => ['code' => 'winter', 'name' => 'Winter', 'enabled' => true, 'exclusive' => false, 'channels' => [], 'scopes' => [], 'actions' => [], 'translations' => []]]])
            ->willReturn(['winter' => ['ok' => false, 'status' => 400, 'error' => 'HTTP 400', 'body' => ['violations' => [['propertyPath' => 'state', 'message' => 'processing']]]]]);

        $result = $this->call(new UpsertCatalogPromotions($client, $this->audit(), 'en_US', $this->adapter()), items: [
            ['code' => 'winter', 'enabled' => true],
            ['code' => 'busy', 'enabled' => false],
            ['code' => 'spring', 'name' => 'Spring', 'channels' => ['WEB'], 'label' => 'Spring', 'scopes' => [['type' => 'for_taxons', 'configuration' => ['taxons' => ['mugs']]]], 'actions' => [['type' => 'percentage_discount', 'configuration' => ['amount' => 0.2]]]],
        ], confirm: true);

        self::assertSame('processing', $result['results']['winter']['reason'], 'the 400 after the write');
        self::assertSame(['enabled' => ['before' => false, 'after' => true]], $result['results']['winter']['diff']);
        self::assertSame('processing', $result['results']['busy']['reason'], 'the probe before the plan');
        self::assertSame('created', $result['results']['spring']['action']);
    }

    public function testTheDeleteRefusesAProcessingPromotionBeforeTheConfirmation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn([
            'busy' => ['ok' => true, 'body' => ['code' => 'busy', 'name' => 'Busy', 'state' => 'processing']],
            'old' => ['ok' => true, 'body' => ['code' => 'old', 'name' => 'Old', 'state' => 'inactive']],
        ]);
        $client->expects(self::once())->method('deleteMany')->with(['old' => 'catalog-promotions/old'])->willReturn(['old' => ['ok' => true]]);

        $result = $this->call(new DeleteCatalogPromotions($client, $this->audit()), codes: ['busy', 'old'], confirm: true);

        self::assertSame('processing', $result['results']['busy']['reason']);
        self::assertSame('deleted', $result['results']['old']['action']);
    }
}
