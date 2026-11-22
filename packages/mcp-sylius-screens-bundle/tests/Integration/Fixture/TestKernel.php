<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Emagma\McpScreensBundle\EmagmaMcpScreensBundle;
use Emagma\McpScreensBundle\ScreenRegistry;
use Emagma\McpSyliusBundle\EmagmaMcpSyliusBundle;
use Emagma\McpSyliusScreensBundle\EmagmaMcpSyliusScreensBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle as a host installs it: the core, the MCP server, Twig, the screens bundle, the Sylius bundle
 * with a line of its own (no line bundle is a dependency here), and every caller allowed.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new McpBundle(),
            new EmagmaMcpCoreBundle(),
            new EmagmaMcpScreensBundle(),
            new EmagmaMcpSyliusBundle(),
            new EmagmaMcpSyliusScreensBundle(),
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
        return sys_get_temp_dir() . '/emagma-mcp-sylius-screens-bundle/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-sylius-screens-bundle/log';
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
        $container->extension('emagma_mcp_sylius', [
            'api_url' => 'https://shop.test/api/v2/admin/',
            'sylius_version' => '2.3',
            'shop_extensions' => '',
            'default_locale' => 'en_US',
            'service_account' => ['email' => 'bot@shop.test', 'password' => 'secret'],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(TestLine::class);
        $services->alias(GrantsProviderInterface::class, 'emagma_mcp_core.grants_provider.allow_all');
        // Private services are removed when nothing uses them.
        $services->alias('test.screen_registry', ScreenRegistry::class)->public();
        $services->alias('test.twig', 'twig')->public();
    }
}
