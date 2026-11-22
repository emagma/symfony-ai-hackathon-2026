<?php

declare(strict_types=1);

namespace Emagma\McpSyliusV2Bundle\Tests\Unit;

use Emagma\McpSyliusBundle\AdminApi\Adapter\DefaultLayoutAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\ApiFeature;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLines;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Emagma\McpSyliusV2Bundle\Sylius2Adapter;
use Emagma\McpSyliusV2Bundle\Sylius2Line;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sylius2Line::class)]
#[CoversClass(Sylius2Adapter::class)]
final class Sylius2LineTest extends TestCase
{
    private const string API = 'http://shop/api/v2/admin/';

    public function testTheLineServes20To23WhateverThePatch(): void
    {
        $lines = new SyliusLines([new Sylius2Line()]);

        self::assertSame(['2.0', '2.1', '2.2', '2.3'], $lines->versions());
        foreach (['2.0', '2.1', '2.2.9', '2.3'] as $version) {
            self::assertInstanceOf(Sylius2Adapter::class, $lines->adapter($lines->configured($version), self::API));
        }
        self::assertFalse($lines->supports(new SyliusVersion('1.14')));
        self::assertFalse($lines->supports(new SyliusVersion('2.4')), 'a new minor is listed once its routes are checked');
    }

    public function testEveryFeatureOfThe2xLineAppearsOnAVersionItServes(): void
    {
        foreach (ApiFeature::cases() as $feature) {
            if (2 === $feature->since()->major) {
                self::assertContains($feature->since()->line(), Sylius2Line::VERSIONS, $feature->value);
            }
        }
    }

    public function testItsRoutesAreTheDefaultLayout(): void
    {
        $default = new DefaultLayoutAdapter(self::API);
        $sylius2 = new Sylius2Adapter(self::API);
        $parents = ['product' => 'MUG', 'taxon' => 'mugs', 'option' => 'size', 'promotion' => 'BF', 'country' => 'FR'];

        foreach (Resource::cases() as $resource) {
            foreach (['GET', 'POST'] as $method) {
                self::assertSame($default->path(Route::collection($resource, $parents), $method), $sylius2->path(Route::collection($resource, $parents), $method));
                if (Resource::PluginCapabilities !== $resource) {
                    self::assertSame($default->path(Route::item($resource, 'X', $parents), $method), $sylius2->path(Route::item($resource, 'X', $parents), $method));
                }
            }
        }
        self::assertSame('promotions/BF/coupons/generate', $sylius2->path(Route::collectionAction(Resource::PromotionCoupon, 'generate', $parents), 'POST'));
        self::assertSame(['code' => 'BF25'], $sylius2->body(Route::collection(Resource::PromotionCoupon, $parents), 'POST', ['code' => 'BF25']), 'the promotion is read off the URI');
        self::assertSame('/shop/api/v2/admin/channels/WEB', (new Sylius2Adapter('https://example.com/shop/api/v2/admin'))->iri(Resource::Channel, 'WEB'));
    }
}
