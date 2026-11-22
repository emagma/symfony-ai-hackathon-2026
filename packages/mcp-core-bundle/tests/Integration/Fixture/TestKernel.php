<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The core as a host installs it, without any bundle built on it: FrameworkBundle, the MCP server, one tool.
 * Closed by default, or open to every caller with the allow-all grants.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(
        private readonly bool $allowAll,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new McpBundle(), new EmagmaMcpCoreBundle()];
    }

    /**
     * Not the package root: Symfony would write a config/reference.php into the bundle's config/.
     */
    public function getProjectDir(): string
    {
        return __DIR__;
    }

    /**
     * One directory per vendor/ and per grants: the package alone and the application that installs it never share a container.
     */
    public function getCacheDir(): string
    {
        return self::varDir() . '/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/' . ($this->allowAll ? 'open' : 'closed') . '/cache';
    }

    public function getLogDir(): string
    {
        return self::varDir() . '/log';
    }

    public static function varDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-core-bundle';
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
                    'registry' => ['tools' => ['Emagma\McpCoreBundle\Tests\Integration\Fixture\\']],
                ],
            ],
        ]);

        $container->extension('emagma_mcp_core', ['permission_domains' => ['Emagma\McpCoreBundle\Tests\Integration\Fixture\\' => 'standalone']]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(EchoTool::class);
        // Nothing of the core uses the map: the bundles built on it do. Public here so that the test reads it.
        $services->alias('test.tool_class_map', 'emagma_mcp_core.tool_class_map')->public();
        if ($this->allowAll) {
            $services->alias(GrantsProviderInterface::class, 'emagma_mcp_core.grants_provider.allow_all');
        }
    }
}
