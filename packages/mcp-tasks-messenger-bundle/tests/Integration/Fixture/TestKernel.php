<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\Caller\NullActorProvider;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Emagma\McpTasksBundle\EmagmaMcpTasksBundle;
use Emagma\McpTasksMessengerBundle\EmagmaMcpTasksMessengerBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * The bundle as a host installs it beside the tasks bundle, which it names as its orchestrator: no
 * `orchestrator` key is set here, the one it prepends is the one that counts. The queue is in memory.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new McpBundle(),
            new EmagmaMcpCoreBundle(),
            new EmagmaMcpTasksBundle(),
            new EmagmaMcpTasksMessengerBundle(),
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
        return sys_get_temp_dir() . '/emagma-mcp-tasks-messenger-bundle/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-tasks-messenger-bundle/log';
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@EmagmaMcpTasksBundle/config/routes.yaml');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->parameters()->set('env(MCP_TASKS_TRANSPORT_DSN)', 'in-memory://');
        // with_constructor_extractor: Symfony 8's default, set so that 7.4 does not deprecate its absence.
        $container->extension('framework', [
            'secret' => 'test',
            'http_client' => true,
            'test' => true,
            'http_method_override' => false,
            'property_info' => ['with_constructor_extractor' => true],
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
        $container->extension('emagma_mcp_tasks', [
            'directory' => '%kernel.cache_dir%/tasks',
            'delegation' => ['token_handler' => StubTokenHandler::class],
        ]);

        $services = $container->services();
        $services->set(StubTokenHandler::class);
        // Framework's default logger writes to stderr.
        $services->set('logger', \Psr\Log\NullLogger::class);
        // No SecurityBundle here: the tools read the caller from the core's store, the worker from the token storage.
        $services->set('security.token_storage', TokenStorage::class);
        $services->set(NullActorProvider::class);
        $services->alias(ActorProviderInterface::class, NullActorProvider::class);
        $services->set('ai.platform.anthropic', \Symfony\AI\Platform\Platform::class)
            ->factory([MockPlatformFactory::class, 'createPlatform'])
            ->args(['']);

        // What the tests read, public.
        foreach ([
            'test.orchestrator' => 'emagma_mcp_tasks.orchestrator',
            'test.handler' => 'emagma_mcp_tasks_messenger.run_step_handler',
            'test.listener' => 'emagma_mcp_tasks_messenger.interrupted_tasks_listener',
            'test.event_dispatcher' => 'event_dispatcher',
        ] as $id => $target) {
            $services->alias($id, $target)->public();
        }
    }
}
