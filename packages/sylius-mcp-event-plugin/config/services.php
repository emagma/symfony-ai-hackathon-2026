<?php

declare(strict_types=1);

use Emagma\SyliusMcpEventPlugin\EventListener\CustomerCreatedListener;
use Emagma\SyliusMcpEventPlugin\Relay\ShopEventRelay;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private();

    // Arguments 1 and 2 are the URL and the secret (the plugin's configuration).
    $services->set('emagma_sylius_mcp_event.relay', ShopEventRelay::class)
        ->args([
            service('messenger.default_bus'),
            '',
            '',
        ]);
    $services->alias(ShopEventRelay::class, 'emagma_sylius_mcp_event.relay');

    $services->set('emagma_sylius_mcp_event.listener.customer_created', CustomerCreatedListener::class)
        ->args([
            service('emagma_sylius_mcp_event.relay'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'postPersist']);
};
