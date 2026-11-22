<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Promotion;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Promotion\UpsertPromotions;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertPromotions::class)]
final class UpsertPromotionsTest extends McpToolTestCase
{
    public function testACreationCopiesTheLabelAndAnUpdateReplacesRulesAndChannelsWhole(): void
    {
        $summer = ['code' => 'summer', 'name' => 'Summer', 'exclusive' => false, 'appliesToDiscounted' => true, 'couponBased' => false,
            'channels' => ['/api/v2/admin/channels/WEB'], 'rules' => [['type' => 'cart_quantity', 'configuration' => ['count' => 2]]], 'actions' => [],
            'translations' => ['en_US' => ['@id' => '/t/1', 'label' => 'Summer sale', 'locale' => 'en_US']]];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US'], ['code' => 'fr_FR']]));
        $client->method('getDecodedMany')->willReturn([
            'summer' => ['ok' => true, 'body' => $summer],
            'xmas' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'half' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['xmas' => ['path' => 'promotions', 'body' => [
                'code' => 'xmas', 'name' => 'Xmas', 'exclusive' => false, 'appliesToDiscounted' => true, 'couponBased' => true, 'priority' => 5,
                'channels' => ['/api/v2/admin/channels/WEB'],
                'actions' => [['type' => 'order_percentage_discount', 'configuration' => ['percentage' => 0.1]]],
                'translations' => ['en_US' => ['label' => 'Xmas -10%', 'locale' => 'en_US'], 'fr_FR' => ['label' => 'Xmas -10%', 'locale' => 'fr_FR']],
            ]]])
            ->willReturn(['xmas' => ['ok' => true, 'body' => ['code' => 'xmas']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['summer' => ['path' => 'promotions/summer', 'body' => array_replace($summer, [
                'rules' => [], 'channels' => ['/api/v2/admin/channels/WEB', '/api/v2/admin/channels/B2B'],
                'translations' => ['en_US' => ['@id' => '/t/1', 'label' => 'Summer!', 'locale' => 'en_US']],
            ])]])
            ->willReturn(['summer' => ['ok' => true, 'body' => $summer]]);

        $result = $this->call(new UpsertPromotions($client, $this->audit(), 'en_US', $this->adapter()), items: [
            ['code' => 'summer', 'rules' => [], 'channels' => ['WEB', 'B2B'], 'label' => 'Summer!', 'name' => 'Summer'],
            ['code' => 'xmas', 'name' => 'Xmas', 'couponBased' => true, 'priority' => 5, 'channels' => ['WEB'], 'label' => 'Xmas -10%', 'actions' => [['type' => 'order_percentage_discount', 'configuration' => ['percentage' => 0.1]]]],
            ['code' => 'half'],
        ], confirm: true);

        self::assertSame(['channels', 'rules', 'en_US.label'], array_keys($result['results']['summer']['diff']));
        self::assertSame('created', $result['results']['xmas']['action']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['name']], $result['results']['half']);
    }
}
