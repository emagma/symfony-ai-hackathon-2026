<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The extension points other bundles feed through `prependExtensionConfig()`:
 *
 *     emagma_sylius_mcp:
 *         lean:
 *             groups:  { <resource class or %param%>: <lean serializer group> }
 *             filters: { <resource class or %param%>: { <GetCollection uriTemplate>: [<filter service id>, ...] } }
 *         extensions:  { <extension name>: <Composer package whose version is published> }
 *
 * The plugin's own entries (CORE_LEAN_GROUPS, CORE_LEAN_FILTERS) are not
 * defaults of the tree - a prototyped node's default is replaced, not merged,
 * as soon as anyone configures it - but are merged first by the extension.
 */
final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'emagma_sylius_mcp';

    /**
     * Resource class => lean group. Matched with `is_a()`, first match wins, so
     * a shop's overriding model (`App\Entity\Product\Product`) inherits it.
     *
     * @var array<string, string>
     */
    public const array CORE_LEAN_GROUPS = [
        \Sylius\Component\Core\Model\Product::class => 'mcp:product:lean',
        \Sylius\Component\Core\Model\Taxon::class => 'mcp:taxon:lean',
        \Sylius\Component\Product\Model\ProductAttribute::class => 'mcp:product_attribute:lean',
        \Sylius\Component\Product\Model\ProductOption::class => 'mcp:product_option:lean',
        \Sylius\Component\Core\Model\ProductVariant::class => 'mcp:product_variant:lean',
        \Sylius\Component\Core\Model\Channel::class => 'mcp:channel:lean',
        \Sylius\Component\Addressing\Model\Country::class => 'mcp:country:lean',
        \Sylius\Component\Currency\Model\Currency::class => 'mcp:currency:lean',
        \Sylius\Component\Locale\Model\Locale::class => 'mcp:locale:lean',
        \Sylius\Component\Addressing\Model\Zone::class => 'mcp:zone:lean',
        \Sylius\Component\Currency\Model\ExchangeRate::class => 'mcp:exchange_rate:lean',
        \Sylius\Component\Core\Model\PaymentMethod::class => 'mcp:payment_method:lean',
        \Sylius\Component\Core\Model\ShippingMethod::class => 'mcp:shipping_method:lean',
        \Sylius\Component\Shipping\Model\ShippingCategory::class => 'mcp:shipping_category:lean',
        \Sylius\Component\Taxation\Model\TaxCategory::class => 'mcp:tax_category:lean',
        \Sylius\Component\Taxation\Model\TaxRate::class => 'mcp:tax_rate:lean',
        \Sylius\Component\Core\Model\TaxonImage::class => 'mcp:taxon_image:lean',
        \Sylius\Component\Core\Model\Order::class => 'mcp:order:lean',
        \Sylius\Component\Core\Model\Payment::class => 'mcp:payment:lean',
        \Sylius\Component\Core\Model\Shipment::class => 'mcp:shipment:lean',
        \Sylius\Component\Core\Model\Customer::class => 'mcp:customer:lean',
        \Sylius\Component\Core\Model\Promotion::class => 'mcp:promotion:lean',
        \Sylius\Component\Core\Model\CatalogPromotion::class => 'mcp:catalog_promotion:lean',
    ];

    /**
     * Resource class => (GetCollection uriTemplate => lean-gated filter ids).
     * Exact class match (API Platform reports the configured model class).
     *
     * @var array<string, array<string, list<string>>>
     */
    public const array CORE_LEAN_FILTERS = [
        '%sylius.model.product.class%' => [
            '/admin/products' => [
                'emagma_sylius_mcp.api.filter.product_attribute_value',
                'emagma_sylius_mcp.api.filter.product_channel_pricing',
            ],
        ],
        '%sylius.model.customer.class%' => [
            '/admin/customers' => [
                'emagma_sylius_mcp.api.filter.customer_criteria',
                'emagma_sylius_mcp.api.filter.customer_order_aggregate',
            ],
        ],
        '%sylius.model.order.class%' => [
            '/admin/orders' => [
                'emagma_sylius_mcp.api.filter.order_criteria',
                'emagma_sylius_mcp.api.filter.order_totals',
                'emagma_sylius_mcp.api.filter.order_sort',
                'emagma_sylius_mcp.api.filter.order_items',
            ],
        ],
        '%sylius.model.promotion.class%' => [
            '/admin/promotions' => [
                'emagma_sylius_mcp.api.filter.promotion_criteria',
            ],
        ],
        '%sylius.model.taxon.class%' => [
            '/admin/taxons' => [
                'emagma_sylius_mcp.api.filter.taxon_criteria',
            ],
        ],
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->arrayNode('lean')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('groups')
                            ->info('Resource class (or %parameter%) => lean serializer group.')
                            ->useAttributeAsKey('resource')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                        ->arrayNode('filters')
                            ->info('Resource class (or %parameter%) => (GetCollection uriTemplate => filter service ids).')
                            ->useAttributeAsKey('resource')
                            ->normalizeKeys(false)
                            ->arrayPrototype()
                                ->useAttributeAsKey('uri_template')
                                ->normalizeKeys(false)
                                ->arrayPrototype()
                                    ->scalarPrototype()->cannotBeEmpty()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('extensions')
                    ->info('Extension name => Composer package whose installed version is published by the capabilities endpoint.')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->validate()
                        ->ifTrue(static fn (array $extensions): bool => [] !== array_filter(
                            array_keys($extensions),
                            static fn (int|string $name): bool => 1 !== preg_match('/^[a-z][a-z0-9_]*$/', (string) $name),
                        ))
                        ->thenInvalid('Extension names are snake_case identifiers (e.g. "sylius_cms"): %s')
                    ->end()
                    ->scalarPrototype()
                        ->cannotBeEmpty()
                        ->validate()
                            ->ifTrue(static fn (mixed $package): bool => !\is_string($package) || 1 !== preg_match('#^[^/\s]+/[^/\s]+$#', $package))
                            ->thenInvalid('An extension names a Composer package ("vendor/name"), got %s')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
