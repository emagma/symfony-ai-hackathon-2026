<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Emagma\McpAgentsBundle\EmagmaMcpAgentsBundle;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\ByHandAgent;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\EveryFiveMinutesAgent;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Psr\Log\NullLogger;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle as a host installs it, on an in-memory SQLite database and in-memory transports: the core, the MCP
 * server, Doctrine and its migrations, Messenger, Scheduler and Notifier, and two agents the bundle finds on its own.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new DoctrineMigrationsBundle(),
            new McpBundle(),
            new EmagmaMcpCoreBundle(),
            new EmagmaMcpAgentsBundle(),
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
        return sys_get_temp_dir() . '/emagma-mcp-agents-bundle/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-agents-bundle/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // with_constructor_extractor: Symfony 8's default, set so that 7.4 does not deprecate its absence.
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'property_info' => ['with_constructor_extractor' => true],
            'lock' => 'flock',
            'messenger' => [
                'transports' => [
                    'mcp_agents' => ['dsn' => 'in-memory://'],
                    'failed_mcp_agents' => ['dsn' => 'in-memory://'],
                ],
            ],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
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
        $container->extension('emagma_mcp_agents', [
            'recipients' => ['ops@example.com'],
            'alert_from' => 'medium',
        ]);

        $services = $container->services();
        // Framework's default logger writes to stderr.
        $services->set('logger', NullLogger::class);

        // Two agents found by autoconfiguration alone: nothing tags them here.
        $services->defaults()->autowire()->autoconfigure();
        $services->set(ByHandAgent::class)->public();
        $services->set(EveryFiveMinutesAgent::class)->public();

        // Private services of the bundle, public here so that the tests read them.
        foreach (['report_store', 'alerter', 'runner', 'schedule_provider', 'run_agent_handler'] as $name) {
            $services->alias('test.' . $name, 'emagma_mcp_agents.' . $name)->public();
        }
        $services->alias('test.tool_class_map', 'emagma_mcp_core.tool_class_map')->public();
    }
}
