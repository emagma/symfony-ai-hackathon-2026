<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\Alert\AgentAlerter;
use Emagma\McpAgentsBundle\Store\AgentReportStoreInterface;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusEventsBundle\Store\ShopEventStoreInterface;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\SymfonyAiHackathon2026Bundle\EmagmaSymfonyAiHackathon2026Bundle;
use Psr\Log\NullLogger;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle with the core and the MCP server of a host. What it takes from the bundles of the agents, the Sylius
 * shop, its events and the tasks is declared synthetic: the container is compiled, so every reference is checked,
 * without those bundles' own wiring (their tests, and the application's, cover it).
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $fraud
     */
    public function __construct(
        private readonly array $fraud = [],
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new McpBundle(),
            new EmagmaMcpCoreBundle(),
            new EmagmaSymfonyAiHackathon2026Bundle(),
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
     * One directory per vendor/ and per configuration: they never share a container.
     */
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/emagma-symfony-ai-hackathon-2026-bundle/' . md5(InstalledVersions::getRootPackage()['install_path'] . serialize($this->fraud)) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/emagma-symfony-ai-hackathon-2026-bundle/log';
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
        $container->extension('emagma_symfony_ai_hackathon_2026', ['fraud_checker_agent' => $this->fraud]);
        $container->parameters()->set('emagma_mcp_sylius.default_locale', 'en_US');

        $services = $container->services();
        $services->set('logger', NullLogger::class);

        foreach ([
            AdminApiClientInterface::class,
            ShopEventStoreInterface::class,
            AgentReportStoreInterface::class,
            AgentAlerter::class,
            AgentRunner::class,
            TaskManager::class,
            ActorProviderInterface::class,
            'ai.platform.anthropic' => PlatformInterface::class,
        ] as $id => $class) {
            $services->set(\is_string($id) ? $id : $class, $class)->synthetic()->public();
        }

        // Private services of the bundle and of the core, public here so that the tests read them.
        foreach ([
            'demos' => 'emagma_symfony_ai_hackathon_2026.demos',
            'tool_class_map' => 'emagma_mcp_core.tool_class_map',
            'permission_resolver' => 'emagma_mcp_core.permission_resolver',
        ] as $name => $id) {
            $services->alias('test.' . $name, $id)->public();
        }
    }
}
