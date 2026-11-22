<?php

declare(strict_types=1);

use Emagma\SyliusMcpPlugin\ApiPlatform\Extension\TaxonTreeOrderExtension;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\CustomerCriteriaFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\CustomerOrderAggregateFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\OrderCriteriaFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\OrderItemsFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\OrderSortFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\OrderTotalsFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\ProductAttributeValueFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\ProductChannelPricingFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\PromotionCriteriaFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\TaxonCriteriaFilter;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Emagma\SyliusMcpPlugin\ApiPlatform\Metadata\LeanFilterResourceMetadataCollectionFactory;
use Emagma\SyliusMcpPlugin\ApiPlatform\Serializer\LeanContextBuilder;
use Emagma\SyliusMcpPlugin\ApiPlatform\Serializer\LeanTranslationTrimNormalizer;
use Emagma\SyliusMcpPlugin\ApiPlatform\State\CapabilitiesProvider;
use Emagma\SyliusMcpPlugin\Command\CreateServiceAccountCommand;
use Emagma\SyliusMcpPlugin\EventListener\LeanVaryListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $aliased = [
        'emagma_sylius_mcp.lean.request_detector' => LeanRequestDetector::class,
        'emagma_sylius_mcp.lean.vary_listener' => LeanVaryListener::class,
        'emagma_sylius_mcp.api.lean_context_builder' => LeanContextBuilder::class,
        'emagma_sylius_mcp.api.lean_translation_trim_normalizer' => LeanTranslationTrimNormalizer::class,
        'emagma_sylius_mcp.api.capabilities_provider' => CapabilitiesProvider::class,
        'emagma_sylius_mcp.command.service_account' => CreateServiceAccountCommand::class,
    ];

    $services->defaults()->private();

    // Lean API payloads: `Prefer: respond-for=lean` swaps the serializer
    // groups for the `mcp:*:lean` ones and enables the filters below.
    // Single source of truth for "is this request in lean mode?".
    // Both the serializer context builder and the lean-gated API Platform
    // filters read from here so they all agree on what the header means.
    $services->set('emagma_sylius_mcp.lean.request_detector', LeanRequestDetector::class)
        ->args([
            service('request_stack'),
        ]);

    // `Vary: Prefer` on API responses (the preference changes the
    // representation of a URL, so any cache in front of the shop must key
    // on it), and the `Sylius-Mcp-Plugin` presence header.
    $services->set('emagma_sylius_mcp.lean.vary_listener', LeanVaryListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onResponse']);

    $services->set('emagma_sylius_mcp.api.lean_context_builder', LeanContextBuilder::class)
        ->decorate('api_platform.serializer.context_builder')
        ->args([
            service('.inner'),
            service('emagma_sylius_mcp.lean.request_detector'),
            // Resource class => lean group: set by the extension from
            // `emagma_sylius_mcp.lean.groups` (core entries first).
            [],
        ]);

    // Cuts lean payloads down to the one locale the caller reads
    // (`Prefer: respond-for=lean; locale=xx`).
    $services->set('emagma_sylius_mcp.api.lean_translation_trim_normalizer', LeanTranslationTrimNormalizer::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
        ])
        ->tag('serializer.normalizer', ['priority' => 128]);

    // Lean-gated attribute filter on /admin/products. Self-disables when
    // the request lacks the lean preference - the admin UI and existing
    // integrations see no behavioural change.
    $services->set('emagma_sylius_mcp.api.filter.product_attribute_value', ProductAttributeValueFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            service('sylius.repository.product_attribute'),
            '%locale%',
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    // Lean-gated channel-scoped pricing filter. Channel is REQUIRED to
    // keep "products in range" meaningful across currencies.
    $services->set('emagma_sylius_mcp.api.filter.product_channel_pricing', ProductChannelPricingFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    // Lean-gated order filters. Sylius declares nothing on the three
    // state machines, the order number or the customer email, and its
    // OrderFilter only sorts by number - these fill exactly those gaps.
    $services->set('emagma_sylius_mcp.api.filter.order_criteria', OrderCriteriaFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    $services->set('emagma_sylius_mcp.api.filter.order_totals', OrderTotalsFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    $services->set('emagma_sylius_mcp.api.filter.order_items', OrderItemsFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            '%sylius.model.order_item.class%',
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    $services->set('emagma_sylius_mcp.api.filter.order_sort', OrderSortFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    // Lean-gated customer filters. Sylius can sort by createdAt but not
    // filter on it, and declares nothing on newsletter consent, account
    // existence or the group code.
    $services->set('emagma_sylius_mcp.api.filter.customer_criteria', CustomerCriteriaFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    $services->set('emagma_sylius_mcp.api.filter.customer_order_aggregate', CustomerOrderAggregateFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            '%sylius.model.order.class%',
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    // Promotion search and scheduling. Sylius declares exactly one
    // search on this collection (coupons.code, exact).
    $services->set('emagma_sylius_mcp.api.filter.promotion_criteria', PromotionCriteriaFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    // Lean-gated taxon filters. Sylius declares no filter at all on the
    // admin taxon collection; `subtreeOf` walks the nested set so a whole
    // branch comes back in one bounded request.
    $services->set('emagma_sylius_mcp.api.filter.taxon_criteria', TaxonCriteriaFilter::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
            service('doctrine'),
            service('logger'),
        ])
        ->tag('api_platform.filter');

    // Lean taxon collections come back in nested-set order (`left`):
    // the DFS pre-order of the tree, siblings as the admin positions
    // them. Above API Platform's OrderExtension (-32), which yields to
    // an existing ORDER BY.
    $services->set('emagma_sylius_mcp.api.extension.taxon_tree_order', TaxonTreeOrderExtension::class)
        ->args([
            service('emagma_sylius_mcp.lean.request_detector'),
        ])
        ->tag('api_platform.doctrine.orm.query_extension.collection', ['priority' => 0]);

    // Append the lean-gated filters to the Sylius admin GetCollection
    // operations without editing vendor XML. Decoration priority 700
    // sits below Sylius's own metadata factories (799) so we run after
    // them.
    $services->set('emagma_sylius_mcp.api.filter.metadata_factory', LeanFilterResourceMetadataCollectionFactory::class)
        ->decorate('api_platform.metadata.resource.metadata_collection_factory', null, 700)
        ->args([
            service('.inner'),
            // Resource class => (uriTemplate => filter ids): set by the
            // extension from `emagma_sylius_mcp.lean.filters`
            // (Configuration::CORE_LEAN_FILTERS first).
            [],
        ]);

    // GET /api/v2/admin/sylius-mcp/capabilities: the MCP extensions
    // this shop carries (ApiResource/Capabilities.php).
    $services->set('emagma_sylius_mcp.api.capabilities_provider', CapabilitiesProvider::class)
        // Extension name => Composer package: set by the extension from
        // `emagma_sylius_mcp.extensions`.
        ->args([
            [],
            service('logger'),
        ])
        ->tag('api_platform.state_provider');

    // The MCP server's service account.
    $services->set('emagma_sylius_mcp.command.service_account', CreateServiceAccountCommand::class)
        ->args([
            service('sylius.repository.admin_user'),
            service('sylius.factory.admin_user'),
            service('doctrine.orm.entity_manager'),
            service('security.user_password_hasher'),
            '%locale%',
            '%env(default::MCP_SERVICE_ACCOUNT_EMAIL)%',
            '%env(default::MCP_SERVICE_ACCOUNT_PASSWORD)%',
        ])
        ->tag('console.command', ['command' => 'sylius-mcp:service-account']);

    // Class aliases: what is injected by class, or named by the host and by
    // the shop extensions (the CMS plugin reads the detector by class).
    foreach ($aliased as $id => $class) {
        $services->alias($class, $id);
    }
};
