<?php

declare(strict_types=1);

namespace Emagma\McpSyliusV2Bundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Nothing to configure: it registers the line of Sylius 2.x, and the shop's version
 * (`emagma_mcp_sylius.sylius_version`) picks the line at runtime.
 */
final class EmagmaMcpSyliusV2Bundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
