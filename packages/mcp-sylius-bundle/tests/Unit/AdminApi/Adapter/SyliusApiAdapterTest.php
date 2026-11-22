<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Adapter;

use Emagma\McpSyliusBundle\AdminApi\Adapter\AbstractSyliusApiAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\DefaultLayoutAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\Exception\UnsupportedRouteException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractSyliusApiAdapter::class)]
#[CoversClass(DefaultLayoutAdapter::class)]
#[CoversClass(Resource::class)]
final class SyliusApiAdapterTest extends TestCase
{
    private const string API = 'http://shop/api/v2/admin/';

    private const array PARENTS = ['product' => 'MUG', 'taxon' => 'mugs', 'option' => 'size', 'promotion' => 'BF', 'country' => 'FR'];

    /**
     * @return iterable<string, array{SyliusApiAdapterInterface}>
     */
    public static function adapters(): iterable
    {
        yield 'the default layout' => [new DefaultLayoutAdapter(self::API)];
        yield 'a line with exceptions' => [new RootCouponsAdapter(self::API)];
    }

    #[DataProvider('adapters')]
    public function testEveryResourceResolvesItsItemsAndCollectionsWithEveryPlaceholderFilled(SyliusApiAdapterInterface $adapter): void
    {
        foreach (Resource::cases() as $resource) {
            $collection = $adapter->path(Route::collection($resource, self::PARENTS));
            self::assertStringNotContainsString('{', $collection, $resource->value);

            if (Resource::PluginCapabilities === $resource) {
                continue; // a singleton
            }
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                $item = $adapter->path(Route::item($resource, 'a b/c', self::PARENTS), $method);
                self::assertStringEndsWith('/a%20b%2Fc', $item, $resource->value . ': the key, URL-encoded, last');
            }
        }
    }

    public function testASubResourceLivesUnderItsParentByDefault(): void
    {
        $adapter = new DefaultLayoutAdapter(self::API);

        self::assertSame('promotions/BF/coupons', $adapter->path(Route::collection(Resource::PromotionCoupon, self::PARENTS), 'POST'));
        self::assertSame('promotions/BF/coupons/X', $adapter->path(Route::item(Resource::PromotionCoupon, 'X', self::PARENTS)));
        self::assertSame('countries/FR/provinces/X', $adapter->path(Route::item(Resource::Province, 'X', self::PARENTS)));
        self::assertSame('products/MUG/images/7', $adapter->path(Route::item(Resource::ProductImage, 7, self::PARENTS)));
        self::assertSame('sylius-mcp/capabilities', $adapter->path(Route::collection(Resource::PluginCapabilities)));
    }

    public function testALineDiffersOnlyWhereItsTablesSay(): void
    {
        $default = new DefaultLayoutAdapter(self::API);
        $line = new RootCouponsAdapter(self::API);

        $differences = [];
        foreach (Resource::cases() as $resource) {
            foreach ([Route::collection($resource, self::PARENTS), Route::item($resource, 'X', self::PARENTS)] as $route) {
                if (Resource::PluginCapabilities === $resource && null !== $route->key) {
                    continue;
                }
                foreach (['GET', 'POST'] as $method) {
                    $path = $default->path($route, $method);
                    if ($path !== $line->path($route, $method)) {
                        $differences[$method . ' ' . $path] = $line->path($route, $method);
                    }
                }
            }
        }

        self::assertSame([
            'POST promotions/BF/coupons' => 'promotion-coupons',
            'GET promotions/BF/coupons/X' => 'promotion-coupons/X',
            'POST promotions/BF/coupons/X' => 'promotion-coupons/X',
            'GET countries/FR/provinces/X' => 'provinces/X',
            'POST countries/FR/provinces/X' => 'provinces/X',
        ], $differences);
    }

    public function testActionsFollowTheItemOrTheCollectionUnlessTheLineSaysOtherwise(): void
    {
        $default = new DefaultLayoutAdapter(self::API);
        $line = new RootCouponsAdapter(self::API);
        $generate = Route::collectionAction(Resource::PromotionCoupon, 'generate', ['promotion' => 'BF']);
        $archive = Route::action(Resource::Promotion, 'BF', 'archive');

        self::assertSame('promotions/BF/coupons/generate', $default->path($generate, 'POST'));
        self::assertSame('promotion-coupons/generate', $line->path($generate, 'POST'));
        self::assertSame('promotions/BF/archive', $default->path($archive, 'PATCH'));
        self::assertSame('promotions/BF/archive', $line->path($archive, 'PATCH'));
        self::assertSame('orders/t0k3n/payments', $default->path(Route::action(Resource::Order, 't0k3n', 'payments')));
    }

    public function testABodyIsLeftAsItIsUnlessTheLineSaysOtherwise(): void
    {
        $coupons = Route::collection(Resource::PromotionCoupon, ['promotion' => 'BF']);

        self::assertSame(['code' => 'BF25'], (new DefaultLayoutAdapter(self::API))->body($coupons, 'POST', ['code' => 'BF25']));
        self::assertSame(['code' => 'BF25', 'promotion' => 'BF'], (new RootCouponsAdapter(self::API))->body($coupons, 'POST', ['code' => 'BF25']));
    }

    public function testAMissingParentIsAProgrammingError(): void
    {
        $this->expectException(UnsupportedRouteException::class);
        $this->expectExceptionMessage('needs "promotion"');

        (new DefaultLayoutAdapter(self::API))->path(Route::item(Resource::PromotionCoupon, 'BF25'));
    }

    public function testASingletonHasNoItem(): void
    {
        $this->expectException(UnsupportedRouteException::class);

        (new DefaultLayoutAdapter(self::API))->path(Route::item(Resource::PluginCapabilities, 'x'));
    }

    public function testTheIriPrefixIsThePathOfTheApiUrl(): void
    {
        self::assertSame('/api/v2/admin/channels/WEB', (new DefaultLayoutAdapter(self::API))->iri(Resource::Channel, 'WEB'));
        self::assertSame('/shop/api/v2/admin/channels/WEB', (new DefaultLayoutAdapter('https://example.com/shop/api/v2/admin'))->iri(Resource::Channel, 'WEB'));
        self::assertSame('/api/v2/admin/provinces/FR-75', (new RootCouponsAdapter(self::API))->iri(Resource::Province, 'FR-75', ['country' => 'FR']));
    }

    public function testAnIriComesBackAsAPathRelativeToTheApi(): void
    {
        $adapter = new DefaultLayoutAdapter(self::API);

        self::assertSame('product-taxons/12', $adapter->pathFromIri('/api/v2/admin/product-taxons/12'));
        self::assertSame('product-taxons/12', $adapter->pathFromIri('http://shop/api/v2/admin/product-taxons/12'));
        self::assertSame('/api/v2/shop/products/MUG', $adapter->pathFromIri('/api/v2/shop/products/MUG'), 'outside the Admin API: as is');
    }

    public function testARouteReadsAsItsPathInTheDefaultLayout(): void
    {
        self::assertSame('promotions/BF/coupons/BF25', (string) Route::item(Resource::PromotionCoupon, 'BF25', ['promotion' => 'BF']));
    }
}
