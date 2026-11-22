<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Emagma\McpCoreBundle\Authorization\AllowAllGrantsProvider;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Emagma\McpScreensBundle\App\ScreenApp;
use Emagma\McpScreensBundle\EmagmaMcpScreensBundle;
use Emagma\McpScreensBundle\ScreenRegistry;
use Emagma\McpScreensBundle\Tests\Unit\Fixture\NoteScreen;
use Psr\Log\NullLogger;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle as a host installs it: the core, the MCP server, Twig, a caller who holds every grant and one screen.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new McpBundle(),
            new EmagmaMcpCoreBundle(),
            new EmagmaMcpScreensBundle(),
        ];
    }

    /**
     * Not the package root: Symfony would write a config/reference.php into the bundle's config/.
     */
    public function getProjectDir(): string
    {
        return __DIR__;
    }

    /**
     * One directory per vendor/: the package alone and the application that installs it never share a container.
     */
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-screens-bundle/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-screens-bundle/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // with_constructor_extractor: Symfony 8's default, set so that 7.4 does not deprecate its absence.
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->extension('mcp', [
            'servers' => [
                'default' => [
                    'name' => 'test',
                    'version' => '1.0.0',
                    'transports' => ['http' => true],
                ],
            ],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(AllowAllGrantsProvider::class);
        $services->alias(GrantsProviderInterface::class, AllowAllGrantsProvider::class);
        // Autoconfigured with the tag: the bundle's contract for a screen.
        $services->set(NoteScreen::class)->autoconfigure();
        // Private services are removed when nothing uses them.
        $services->alias('test.screen_app', ScreenApp::class)->public();
        $services->alias('test.screen_registry', ScreenRegistry::class)->public();
        $services->alias('test.instructions', 'emagma_mcp_core.instructions')->public();
    }
}
