<?php

declare(strict_types=1);

namespace Emagma\McpSyliusV2Bundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Emagma\McpSyliusBundle\EmagmaMcpSyliusBundle;
use Emagma\McpSyliusV2Bundle\EmagmaMcpSyliusV2Bundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle as a host installs it: FrameworkBundle, the MCP server, the core, the Sylius bundle and the line of 2.x,
 * every caller allowed.
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
        return [new FrameworkBundle(), new McpBundle(), new EmagmaMcpCoreBundle(), new EmagmaMcpSyliusBundle(), new EmagmaMcpSyliusV2Bundle()];
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
        return self::varDir() . '/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/cache';
    }

    public function getLogDir(): string
    {
        return self::varDir() . '/log';
    }

    public static function varDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-sylius-v2-bundle';
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
        $services->alias(GrantsProviderInterface::class, 'emagma_mcp_core.grants_provider.allow_all');
        $services->alias('test.admin_api_client', 'emagma_mcp_sylius.admin_api.client')->public();
        $services->alias('test.sylius_version', 'emagma_mcp_sylius.version')->public();
        $services->alias('test.lines', 'emagma_mcp_sylius.lines')->public();
        $services->alias('test.line', 'emagma_mcp_sylius_v2.line')->public();
    }
}
