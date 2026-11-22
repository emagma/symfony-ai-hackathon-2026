<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Emagma\McpAuditBundle\EmagmaMcpAuditBundle;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\Caller\NullActorProvider;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;
use Psr\Log\NullLogger;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle as a host installs it, on an in-memory SQLite database: the core, the MCP server,
 * Doctrine and its migrations, and a backend that says how its payloads reference a customer.
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
            new EmagmaMcpAuditBundle(),
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
        return sys_get_temp_dir() . '/emagma-mcp-audit-bundle/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-audit-bundle/log';
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

        $services = $container->services();
        // Framework's default logger writes to stderr: the lost entries the writer logs are asserted elsewhere.
        $services->set('logger', NullLogger::class);
        $services->set(CustomerReferences::class);
        $services->alias(SubjectReferencesInterface::class, CustomerReferences::class);
        // No SecurityBundle here: the writes are recorded without actor, as from the command line.
        $services->set(NullActorProvider::class);
        $services->alias(ActorProviderInterface::class, NullActorProvider::class);
    }
}
