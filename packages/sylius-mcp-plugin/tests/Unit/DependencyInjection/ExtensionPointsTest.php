<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\DependencyInjection;

use Emagma\SyliusMcpPlugin\DependencyInjection\Compiler\ResolveLeanMapsPass;
use Emagma\SyliusMcpPlugin\DependencyInjection\Configuration;
use Emagma\SyliusMcpPlugin\DependencyInjection\EmagmaSyliusMcpExtension;
use Emagma\SyliusMcpPlugin\DependencyInjection\LeanMaps;
use Emagma\SyliusMcpPlugin\Tests\Unit\DependencyInjection\Fixture\ContributingExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;

/**
 * The path another bundle takes: `prepend()` → the plugin's `load()` →
 * ResolveLeanMapsPass, down to the arguments of the three services.
 */
#[CoversClass(EmagmaSyliusMcpExtension::class)]
#[CoversClass(ResolveLeanMapsPass::class)]
#[CoversClass(LeanMaps::class)]
final class ExtensionPointsTest extends TestCase
{
    private const string PAGE_CLASS = 'Acme\Cms\Entity\Page';

    private const string SHOP_ORDER_CLASS = 'App\Entity\Order\Order';

    public function testWithoutContributionTheServicesGetTheCoreMapsResolved(): void
    {
        $container = $this->compile([]);

        self::assertSame(Configuration::CORE_LEAN_GROUPS, $this->leanGroups($container));
        self::assertSame([
            'App\Entity\Product\Product',
            'App\Entity\Customer\Customer',
            self::SHOP_ORDER_CLASS,
            'App\Entity\Promotion\Promotion',
            'App\Entity\Taxonomy\Taxon',
        ], array_keys($this->filters($container)));
        self::assertSame(
            array_values(Configuration::CORE_LEAN_FILTERS),
            array_values($this->filters($container)),
        );
        self::assertSame([], $this->extensions($container));
    }

    public function testAContributingBundleReachesTheBuilderTheFactoryAndTheProvider(): void
    {
        $container = $this->compile([
            new ContributingExtension('acme_cms', [
                'lean' => [
                    'groups' => ['%acme_cms.model.page.class%' => 'mcp:cms_page:lean'],
                    'filters' => [
                        '%acme_cms.model.page.class%' => ['/admin/cms/pages' => ['acme_cms.filter.page_criteria']],
                    ],
                ],
                'extensions' => ['sylius_cms' => 'sylius/cms-plugin'],
            ]),
        ]);

        $groups = $this->leanGroups($container);
        self::assertSame('mcp:cms_page:lean', $groups[self::PAGE_CLASS]);
        // Core first: the group map is matched in order.
        self::assertSame(array_keys(Configuration::CORE_LEAN_GROUPS), \array_slice(array_keys($groups), 0, \count(Configuration::CORE_LEAN_GROUPS)));
        self::assertSame(self::PAGE_CLASS, array_key_last($groups));

        self::assertSame(['/admin/cms/pages' => ['acme_cms.filter.page_criteria']], $this->filters($container)[self::PAGE_CLASS]);
        self::assertSame(['sylius_cms' => 'sylius/cms-plugin'], $this->extensions($container));
    }

    public function testContributedFiltersAreAppendedToTheCoreOnesWithoutDuplicates(): void
    {
        $container = $this->compile([
            // A literal class and the parameter naming it must add up.
            new ContributingExtension('acme_orders', [
                'lean' => ['filters' => [self::SHOP_ORDER_CLASS => ['/admin/orders' => [
                    'acme.filter.order_channel',
                    'emagma_sylius_mcp.api.filter.order_sort',
                ]]]],
            ]),
            new ContributingExtension('acme_more', [
                'lean' => ['filters' => ['%sylius.model.order.class%' => [
                    '/admin/orders' => ['acme.filter.order_channel', 'acme.filter.order_tag'],
                    '/admin/orders/archived' => ['acme.filter.order_tag'],
                ]]],
            ]),
        ]);

        $orders = $this->filters($container)[self::SHOP_ORDER_CLASS];
        self::assertSame([
            'emagma_sylius_mcp.api.filter.order_criteria',
            'emagma_sylius_mcp.api.filter.order_totals',
            'emagma_sylius_mcp.api.filter.order_sort',
            'emagma_sylius_mcp.api.filter.order_items',
            'acme.filter.order_channel',
            'acme.filter.order_tag',
        ], $orders['/admin/orders']);
        self::assertSame(['acme.filter.order_tag'], $orders['/admin/orders/archived']);
    }

    public function testAContributedGroupForAnExistingClassReplacesItInPlace(): void
    {
        $container = $this->compile([
            new ContributingExtension('acme', [
                'lean' => ['groups' => [\Sylius\Component\Core\Model\Order::class => 'acme:order:lean']],
            ]),
        ]);

        $groups = $this->leanGroups($container);
        self::assertSame(array_keys(Configuration::CORE_LEAN_GROUPS), array_keys($groups));
        self::assertSame('acme:order:lean', $groups[\Sylius\Component\Core\Model\Order::class]);
    }

    public function testAnUnknownParameterFailsTheCompilation(): void
    {
        $this->expectException(ParameterNotFoundException::class);

        $this->compile([
            new ContributingExtension('acme', [
                'lean' => ['groups' => ['%acme.model.missing.class%' => 'acme:missing:lean']],
            ]),
        ]);
    }

    public function testThePluginMapsItsApiResourceAndSerializerGroups(): void
    {
        $container = new ContainerBuilder();
        (new EmagmaSyliusMcpExtension())->prepend($container);

        $apiPlatform = $container->getExtensionConfig('api_platform');
        self::assertCount(1, $apiPlatform);
        $paths = $apiPlatform[0]['mapping']['paths'];
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertIsString($paths[0]);
        self::assertSame(
            realpath(\dirname(__DIR__, 3) . '/src/ApiResource'),
            realpath($paths[0]),
        );

        self::assertCount(1, $container->getExtensionConfig('framework'));
    }

    /**
     * @param list<ContributingExtension> $contributors
     */
    private function compile(array $contributors): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('sylius.model.product.class', 'App\Entity\Product\Product');
        $container->setParameter('sylius.model.customer.class', 'App\Entity\Customer\Customer');
        $container->setParameter('sylius.model.order.class', self::SHOP_ORDER_CLASS);
        $container->setParameter('sylius.model.promotion.class', 'App\Entity\Promotion\Promotion');
        $container->setParameter('sylius.model.taxon.class', 'App\Entity\Taxonomy\Taxon');
        $container->setParameter('acme_cms.model.page.class', self::PAGE_CLASS);

        foreach ($contributors as $contributor) {
            $container->registerExtension($contributor);
        }
        $container->registerExtension(new EmagmaSyliusMcpExtension());
        // The kernel loads every registered extension; the bare DI pass only
        // those with a configuration.
        $container->loadFromExtension(EmagmaSyliusMcpExtension::ALIAS, []);

        (new MergeExtensionConfigurationPass())->process($container);
        (new ResolveLeanMapsPass())->process($container);

        return $container;
    }

    /**
     * @return array<string, string>
     */
    private function leanGroups(ContainerBuilder $container): array
    {
        /** @var array<string, string> $groups */
        $groups = $container
            ->getDefinition(EmagmaSyliusMcpExtension::LEAN_CONTEXT_BUILDER_ID)
            ->getArgument(EmagmaSyliusMcpExtension::LEAN_CONTEXT_BUILDER_MAP_ARGUMENT);

        return $groups;
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function filters(ContainerBuilder $container): array
    {
        /** @var array<string, array<string, list<string>>> $filters */
        $filters = $container
            ->getDefinition(EmagmaSyliusMcpExtension::FILTER_METADATA_FACTORY_ID)
            ->getArgument(EmagmaSyliusMcpExtension::FILTER_METADATA_FACTORY_MAP_ARGUMENT);

        return $filters;
    }

    /**
     * @return array<string, string>
     */
    private function extensions(ContainerBuilder $container): array
    {
        /** @var array<string, string> $extensions */
        $extensions = $container
            ->getDefinition(EmagmaSyliusMcpExtension::CAPABILITIES_PROVIDER_ID)
            ->getArgument(EmagmaSyliusMcpExtension::CAPABILITIES_PROVIDER_EXTENSIONS_ARGUMENT);

        return $extensions;
    }
}
