<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The screens of a Sylius shop, tagged for the screens bundle. Its Twig namespace is `@EmagmaMcpSyliusScreens` (`templates/`).
 */
final class EmagmaMcpSyliusScreensBundle extends AbstractBundle
{
    /**
     * The package root: Twig finds `templates/` there.
     */
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
