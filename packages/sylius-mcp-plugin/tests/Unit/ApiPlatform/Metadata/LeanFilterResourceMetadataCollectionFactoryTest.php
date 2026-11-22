<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Metadata;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use Emagma\SyliusMcpPlugin\ApiPlatform\Metadata\LeanFilterResourceMetadataCollectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeanFilterResourceMetadataCollectionFactory::class)]
final class LeanFilterResourceMetadataCollectionFactoryTest extends TestCase
{
    private const string PRODUCT_CLASS = 'Sylius\Component\Core\Model\Product';

    private const string ORDER_CLASS = 'Sylius\Component\Core\Model\Order';

    public function testItAppendsTheConfiguredFiltersToTheMatchingOperation(): void
    {
        $factory = $this->factory(
            [self::PRODUCT_CLASS => ['/admin/products' => ['filter.attribute', 'filter.pricing']]],
            $this->collection(self::PRODUCT_CLASS, '/admin/products', ['vendor.filter']),
        );

        $filters = $this->collectionFilters($factory->create(self::PRODUCT_CLASS));

        self::assertSame(['vendor.filter', 'filter.attribute', 'filter.pricing'], $filters);
    }

    public function testItLeavesAnUnmappedResourceAlone(): void
    {
        $factory = $this->factory(
            [self::PRODUCT_CLASS => ['/admin/products' => ['filter.attribute']]],
            $this->collection(self::ORDER_CLASS, '/admin/orders', ['vendor.filter']),
        );

        $filters = $this->collectionFilters($factory->create(self::ORDER_CLASS));

        self::assertSame(['vendor.filter'], $filters);
    }

    public function testItLeavesAnUnmappedOperationAlone(): void
    {
        // Same resource, different operation: the shop collection must not
        // inherit the admin filters.
        $factory = $this->factory(
            [self::PRODUCT_CLASS => ['/admin/products' => ['filter.attribute']]],
            $this->collection(self::PRODUCT_CLASS, '/shop/products', ['vendor.filter']),
        );

        $filters = $this->collectionFilters($factory->create(self::PRODUCT_CLASS));

        self::assertSame(['vendor.filter'], $filters);
    }

    public function testItIsIdempotent(): void
    {
        $factory = $this->factory(
            [self::PRODUCT_CLASS => ['/admin/products' => ['filter.attribute']]],
            $this->collection(self::PRODUCT_CLASS, '/admin/products', ['vendor.filter', 'filter.attribute']),
        );

        $filters = $this->collectionFilters($factory->create(self::PRODUCT_CLASS));

        self::assertSame(['vendor.filter', 'filter.attribute'], $filters);
    }

    public function testItServesSeveralResourcesFromTheSameDecorator(): void
    {
        $map = [
            self::PRODUCT_CLASS => ['/admin/products' => ['filter.attribute']],
            self::ORDER_CLASS => ['/admin/orders' => ['filter.order_criteria']],
        ];

        $product = $this->factory($map, $this->collection(self::PRODUCT_CLASS, '/admin/products', []));
        $order = $this->factory($map, $this->collection(self::ORDER_CLASS, '/admin/orders', []));

        self::assertSame(['filter.attribute'], $this->collectionFilters($product->create(self::PRODUCT_CLASS)));
        self::assertSame(['filter.order_criteria'], $this->collectionFilters($order->create(self::ORDER_CLASS)));
    }

    public function testItIgnoresNonCollectionOperations(): void
    {
        $resource = (new ApiResource())->withOperations(new Operations([
            'get' => (new Get())->withUriTemplate('/admin/products/{code}'),
        ]));
        $collection = new ResourceMetadataCollection(self::PRODUCT_CLASS, [$resource]);

        $factory = $this->factory(
            [self::PRODUCT_CLASS => ['/admin/products/{code}' => ['filter.attribute']]],
            $collection,
        );

        self::assertNull($this->filtersOf($factory->create(self::PRODUCT_CLASS), 'get'));
    }

    /**
     * @param array<string, array<string, list<string>>> $map
     */
    private function factory(array $map, ResourceMetadataCollection $decoratedResult): LeanFilterResourceMetadataCollectionFactory
    {
        $decorated = $this->createMock(ResourceMetadataCollectionFactoryInterface::class);
        $decorated->method('create')->willReturn($decoratedResult);

        return new LeanFilterResourceMetadataCollectionFactory($decorated, $map);
    }

    /**
     * @param list<string> $filters
     */
    private function collection(string $resourceClass, string $uriTemplate, array $filters): ResourceMetadataCollection
    {
        $operation = (new GetCollection())->withUriTemplate($uriTemplate)->withFilters($filters);
        $resource = (new ApiResource())->withOperations(new Operations(['collection' => $operation]));

        return new ResourceMetadataCollection($resourceClass, [$resource]);
    }

    /**
     * @return list<string>
     */
    private function collectionFilters(ResourceMetadataCollection $collection): array
    {
        return $this->filtersOf($collection, 'collection') ?? [];
    }

    /**
     * Operations has no get(); it is only traversable.
     *
     * @return list<string>|null
     */
    private function filtersOf(ResourceMetadataCollection $collection, string $operationName): ?array
    {
        $operations = $collection[0]->getOperations();
        self::assertNotNull($operations);

        foreach ($operations as $name => $operation) {
            if ($name === $operationName) {
                return $operation->getFilters();
            }
        }

        self::fail(sprintf('operation "%s" not found', $operationName));
    }
}
