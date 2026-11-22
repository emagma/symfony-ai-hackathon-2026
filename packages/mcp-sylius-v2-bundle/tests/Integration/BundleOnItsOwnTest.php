<?php

declare(strict_types=1);

namespace Emagma\McpSyliusV2Bundle\Tests\Integration;

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLines;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Emagma\McpSyliusV2Bundle\Sylius2Adapter;
use Emagma\McpSyliusV2Bundle\Sylius2Line;
use Emagma\McpSyliusV2Bundle\Tests\Integration\Fixture\TestKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A real compiled kernel: the line is registered with the Sylius bundle and serves 2.0 to 2.3.
 */
#[CoversNothing]
final class BundleOnItsOwnTest extends TestCase
{
    private const string API = 'https://shop.test/api/v2/admin/';

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(TestKernel::varDir());
    }

    public function testTheLineIsRegisteredAndTagged(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();
        $container = $kernel->getContainer();

        self::assertInstanceOf(Sylius2Line::class, $container->get('test.line'));
    }

    public function testTheSyliusBundleServesEveryVersionOfThe2xLine(): void
    {
        $lines = $this->boot()->get('test.lines');
        self::assertInstanceOf(SyliusLines::class, $lines);

        self::assertSame(['2.0', '2.1', '2.2', '2.3'], $lines->versions());
        foreach (['2.0', '2.1', '2.2', '2.3', '2.3.4'] as $version) {
            self::assertInstanceOf(Sylius2Adapter::class, $lines->adapter($lines->configured($version), self::API), $version);
        }
        self::assertFalse($lines->supports(new SyliusVersion('2.4')));
    }

    public function testTheShopIsReachedThroughTheLine(): void
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

        return $kernel->getContainer();
    }
}
