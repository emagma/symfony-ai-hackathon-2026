<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Emagma\McpSyliusEventsBundle\EmagmaMcpSyliusEventsBundle;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The bundle as a host installs it, on an in-memory SQLite database: the framework with Symfony Webhook, Doctrine
 * and its migrations, and the route the framework brings for the webhooks.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string SECRET = 'a-shared-secret';

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new DoctrineMigrationsBundle(),
            new EmagmaMcpSyliusEventsBundle(),
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
        return sys_get_temp_dir() . '/emagma-mcp-sylius-events-bundle/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-sylius-events-bundle/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
        ]);

        $container->parameters()->set('env(MCP_EVENT_WEBHOOK_SECRET)', self::SECRET);

        $services = $container->services();
        // Framework's default logger writes to stderr.
        $services->set('logger', NullLogger::class);
        $services->set(ClockInterface::class, Clock::class);

        // Private services of the bundle, public here so that the tests read them.
        foreach (['store', 'consumer', 'request_parser'] as $name) {
            $services->alias('test.' . $name, 'emagma_mcp_sylius_events.' . $name)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@FrameworkBundle/Resources/config/routing/webhook.php')->prefix('/webhook');
    }
}
