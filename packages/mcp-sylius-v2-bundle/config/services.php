<?php

declare(strict_types=1);

use Emagma\McpSyliusV2Bundle\Sylius2Line;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('emagma_mcp_sylius_v2.line', Sylius2Line::class)
        ->tag('emagma_mcp_sylius.line');
    $services->alias(Sylius2Line::class, 'emagma_mcp_sylius_v2.line');
};
