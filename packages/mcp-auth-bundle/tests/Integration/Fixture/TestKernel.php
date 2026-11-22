<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\Tests\Integration\Fixture;

use Composer\InstalledVersions;
use Emagma\McpAuthBundle\EmagmaMcpAuthBundle;
use Emagma\McpAuthBundle\McpAuthenticationEntryPoint;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The bundle as a host installs it: the core, the firewall naming its entry point and a token handler,
 * the routes it imports, and an endpoint that answers with the grants the request holds.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SecurityBundle(),
            new McpBundle(),
            new EmagmaMcpCoreBundle(),
            new EmagmaMcpAuthBundle(),
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
        return self::varDir() . '/' . md5(InstalledVersions::getRootPackage()['install_path']) . '/cache';
    }

    public function getLogDir(): string
    {
        return self::varDir() . '/log';
    }

    public static function varDir(): string
    {
        return sys_get_temp_dir() . '/emagma-mcp-auth-bundle';
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
                    'registry' => '*',
                ],
            ],
        ]);
        $container->extension('emagma_mcp_auth', [
            'authorization_servers' => ['https://idp.example.com/realms/test'],
        ]);
        $container->extension('security', [
            'firewalls' => [
                'mcp' => [
                    'pattern' => '^/_mcp',
                    'stateless' => true,
                    'entry_point' => McpAuthenticationEntryPoint::class,
                    'access_token' => ['token_handler' => FakeTokenHandler::class],
                ],
            ],
            'access_control' => [
                ['path' => '^/_mcp', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ],
        ]);

        $services = $container->services()->defaults()->autowire();
        $services->set(FakeTokenHandler::class);
        $services->set(GrantsEndpoint::class)->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(\dirname(__DIR__, 3) . '/config/routes.yaml');
        $routes->add('test_endpoint', '/_mcp')->controller(GrantsEndpoint::class);
    }
}
