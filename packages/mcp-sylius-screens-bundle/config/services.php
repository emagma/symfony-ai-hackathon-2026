<?php

declare(strict_types=1);

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Tool\Address\UpdateAddress;
use Emagma\McpSyliusBundle\Tool\Channel\GetChannels;
use Emagma\McpSyliusBundle\Tool\ProductVariant\GetProductVariants;
use Emagma\McpSyliusBundle\Tool\ProductVariant\SearchProductVariants;
use Emagma\McpSyliusBundle\Tool\ProductVariant\UpsertProductVariants;
use Emagma\McpSyliusScreensBundle\Screen\AddressScreen;
use Emagma\McpSyliusScreensBundle\Screen\ProductVariantsScreen;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Explicit definitions, ids prefixed by the alias, the tag of the screens bundle set by name.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private();

    $services->set('emagma_mcp_sylius_screens.screen.product_variants', ProductVariantsScreen::class)
        ->args([
            service(SearchProductVariants::class),
            service(GetProductVariants::class),
            service(GetChannels::class),
            service(UpsertProductVariants::class),
        ])
        ->tag('emagma_mcp_screens.screen');
    $services->alias(ProductVariantsScreen::class, 'emagma_mcp_sylius_screens.screen.product_variants');

    $services->set('emagma_mcp_sylius_screens.screen.address', AddressScreen::class)
        ->args([
            service(AdminApiClientInterface::class),
            service(UpdateAddress::class),
        ])
        ->tag('emagma_mcp_screens.screen');
    $services->alias(AddressScreen::class, 'emagma_mcp_sylius_screens.screen.address');
};
