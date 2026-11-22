<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle;

use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Brings its own wiring as prepended defaults (the app on the core's server, its permission domain) and
 * tags every `ScreenInterface` service. Its Twig namespace is `@EmagmaMcpScreens` (`templates/`).
 */
final class EmagmaMcpScreensBundle extends AbstractBundle
{
    /**
     * The tag of a screen, set by autoconfiguration on every `ScreenInterface`.
     */
    public const string SCREEN_TAG = 'emagma_mcp_screens.screen';

    /**
     * The package root: Twig finds `templates/` there.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\McpScreensBundle\\', 'core');
        McpCoreConfig::prependServerConfig($builder, ['registry' => ['apps' => ['Emagma\\McpScreensBundle\\App\\']]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->registerForAutoconfiguration(ScreenInterface::class)->addTag(self::SCREEN_TAG);
    }
}
