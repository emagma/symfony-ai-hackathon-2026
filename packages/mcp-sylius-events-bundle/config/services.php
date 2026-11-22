<?php

declare(strict_types=1);

use Emagma\McpSyliusEventsBundle\EmagmaMcpSyliusEventsBundle;
use Emagma\McpSyliusEventsBundle\Store\DoctrineShopEventStore;
use Emagma\McpSyliusEventsBundle\Store\ShopEventStoreInterface;
use Emagma\McpSyliusEventsBundle\Webhook\ShopEventConsumer;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\Webhook\Client\RequestParser;

/*
 * Explicit definitions, ids prefixed by the alias. The store interface is what the readers of other bundles inject.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Symfony's own parser: the signature the shop's Symfony Webhook transport computes.
    $services->set(EmagmaMcpSyliusEventsBundle::REQUEST_PARSER, RequestParser::class);

    $services->set('emagma_mcp_sylius_events.store', DoctrineShopEventStore::class)
        ->args([
            service('doctrine.orm.entity_manager'),
        ]);
    $services->alias(ShopEventStoreInterface::class, 'emagma_mcp_sylius_events.store');

    $services->set('emagma_mcp_sylius_events.consumer', ShopEventConsumer::class)
        ->args([
            service('emagma_mcp_sylius_events.store'),
            service(ClockInterface::class),
            service(EventDispatcherInterface::class),
        ])
        ->tag('remote_event.consumer', ['consumer' => ShopEventConsumer::WEBHOOK]);
};
