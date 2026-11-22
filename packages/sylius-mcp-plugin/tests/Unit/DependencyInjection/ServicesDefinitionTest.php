<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\DependencyInjection;

use Emagma\SyliusMcpPlugin\ApiPlatform\Metadata\LeanFilterResourceMetadataCollectionFactory;
use Emagma\SyliusMcpPlugin\DependencyInjection\EmagmaSyliusMcpExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Guards the hand-written `config/services.php`, loaded through the extension
 * without any contribution (the core lean maps only). Not a full compile, but
 * it catches the mistakes that actually happen here: a broken file, a renamed
 * class, and argument structures that silently change shape.
 */
#[CoversNothing]
final class ServicesDefinitionTest extends TestCase
{
    private const string FACTORY_ID = 'emagma_sylius_mcp.api.filter.metadata_factory';

    public function testTheServicesFileLoads(): void
    {
        self::assertNotEmpty($this->container()->getDefinitions());
    }

    public function testTheFilterMetadataFactoryDecoratesApiPlatformBelowSylius(): void
    {
        $definition = $this->container()->getDefinition(self::FACTORY_ID);

        self::assertSame(LeanFilterResourceMetadataCollectionFactory::class, $definition->getClass());

        $decoration = $definition->getDecoratedService();
        self::assertNotNull($decoration);
        self::assertSame('api_platform.metadata.resource.metadata_collection_factory', $decoration[0]);
        // Sylius's own metadata factories sit at 799; we must run after them.
        self::assertLessThan(799, $decoration[2]);
    }

    public function testTheFilterMapIsKeyedByResourceThenUriTemplate(): void
    {
        $definition = $this->container()->getDefinition(self::FACTORY_ID);

        self::assertEquals(new Reference('.inner'), $definition->getArgument(0));

        $map = $definition->getArgument(1);
        self::assertSame(
            [
                '%sylius.model.product.class%',
                '%sylius.model.customer.class%',
                '%sylius.model.order.class%',
                '%sylius.model.promotion.class%',
                '%sylius.model.taxon.class%',
            ],
            array_keys($map),
        );
        self::assertSame(['/admin/products'], array_keys($map['%sylius.model.product.class%']));
        self::assertSame([
            'emagma_sylius_mcp.api.filter.product_attribute_value',
            'emagma_sylius_mcp.api.filter.product_channel_pricing',
        ], array_values($map['%sylius.model.product.class%']['/admin/products']));

        self::assertSame(['/admin/customers'], array_keys($map['%sylius.model.customer.class%']));
        self::assertSame(
            [
                'emagma_sylius_mcp.api.filter.customer_criteria',
                'emagma_sylius_mcp.api.filter.customer_order_aggregate',
            ],
            array_values($map['%sylius.model.customer.class%']['/admin/customers']),
        );

        self::assertSame(['/admin/orders'], array_keys($map['%sylius.model.order.class%']));
        self::assertSame([
            'emagma_sylius_mcp.api.filter.order_criteria',
            'emagma_sylius_mcp.api.filter.order_totals',
            'emagma_sylius_mcp.api.filter.order_sort',
            'emagma_sylius_mcp.api.filter.order_items',
        ], array_values($map['%sylius.model.order.class%']['/admin/orders']));

        self::assertSame(['/admin/promotions'], array_keys($map['%sylius.model.promotion.class%']));
        self::assertSame(
            ['emagma_sylius_mcp.api.filter.promotion_criteria'],
            array_values($map['%sylius.model.promotion.class%']['/admin/promotions']),
        );

        self::assertSame(['/admin/taxons'], array_keys($map['%sylius.model.taxon.class%']));
        self::assertSame(
            ['emagma_sylius_mcp.api.filter.taxon_criteria'],
            array_values($map['%sylius.model.taxon.class%']['/admin/taxons']),
        );
    }

    public function testEveryFilterReferencedByTheMapIsDefinedAndTagged(): void
    {
        $container = $this->container();
        $map = $container->getDefinition(self::FACTORY_ID)->getArgument(1);

        foreach ($map as $byUriTemplate) {
            foreach ($byUriTemplate as $filterIds) {
                foreach ($filterIds as $filterId) {
                    self::assertTrue(
                        $container->hasDefinition($filterId),
                        sprintf('filter service "%s" is referenced but not defined', $filterId),
                    );
                    self::assertArrayHasKey(
                        'api_platform.filter',
                        $container->getDefinition($filterId)->getTags(),
                        sprintf('filter service "%s" is not tagged api_platform.filter', $filterId),
                    );
                }
            }
        }
    }

    public function testTheTaxonTreeOrderExtensionRunsAboveApiPlatformsOrderExtension(): void
    {
        $definition = $this->container()->getDefinition('emagma_sylius_mcp.api.extension.taxon_tree_order');

        $tags = $definition->getTag('api_platform.doctrine.orm.query_extension.collection');
        self::assertCount(1, $tags);
        // API Platform's OrderExtension sits at -32 and yields to an existing ORDER BY.
        self::assertGreaterThan(-32, $tags[0]['priority']);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new EmagmaSyliusMcpExtension())->load([], $container);

        return $container;
    }
}
