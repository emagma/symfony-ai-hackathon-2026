<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Integration;

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\Tests\Integration\Fixture\TestKernel;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A real compiled kernel: the bundle holds together with the core and one line, without any other bundle built on it.
 */
#[CoversNothing]
final class BundleOnItsOwnTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(TestKernel::varDir());
    }

    public function testEveryToolOfTheBundleIsOnTheServer(): void
    {
        $container = $this->boot();
        $registry = $container->get('emagma_mcp_core.registry');
        self::assertInstanceOf(RegistryInterface::class, $registry);

        $names = array_map(static fn (Tool $tool): string => $tool->name, $registry->getTools()->references);

        $expected = [];
        foreach (SyliusSources::tools()->classes() as $class) {
            foreach ((new \ReflectionClass($class))->getAttributes(McpTool::class) as $attribute) {
                $expected[] = $attribute->newInstance()->name;
            }
        }
        self::assertGreaterThan(100, \count($expected));
        self::assertEqualsCanonicalizing($expected, $names);
        foreach (['search_products', 'upsert_taxons', 'cancel_order', 'delete_zones'] as $tool) {
            self::assertContains($tool, $names);
        }
    }

    public function testTheShopIsReachedThroughTheLineOfTheConfiguredVersion(): void
    {
        $container = $this->boot();

        self::assertInstanceOf(AdminApiClientInterface::class, $container->get('test.admin_api_client'));
        $version = $container->get('test.sylius_version');
        self::assertInstanceOf(SyliusVersion::class, $version);
        self::assertSame('2.3', $version->line());
    }

    private function boot(): ContainerInterface
    {
        $kernel = new TestKernel();
        $kernel->boot();

        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        // The registry is empty until the server's builder has run.
        $container->get('emagma_mcp_core.server');

        return $container;
    }
}
