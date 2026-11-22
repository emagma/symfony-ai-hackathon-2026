<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Emagma\McpKnowledgeBundle\EmagmaMcpKnowledgeBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle as a host installs it: the core, the MCP server, and the procedures of the unit fixtures.
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
        return [new FrameworkBundle(), new McpBundle(), new EmagmaMcpCoreBundle(), new EmagmaMcpKnowledgeBundle()];
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
        return sys_get_temp_dir() . '/emagma-mcp-knowledge-bundle';
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
        $container->extension('emagma_mcp_knowledge', [
            'directory' => \dirname(__DIR__, 2) . '/Unit/fixtures',
            'index_file' => self::varDir() . '/index.sqlite',
        ]);

        $services = $container->services();
        $services->alias(GrantsProviderInterface::class, 'emagma_mcp_core.grants_provider.allow_all');
        // Private ids the test reads.
        $services->alias('test.knowledge_index', 'emagma_mcp_knowledge.index')->public();
        $services->alias('test.knowledge_command', 'emagma_mcp_knowledge.command.index')->public();
    }
}
