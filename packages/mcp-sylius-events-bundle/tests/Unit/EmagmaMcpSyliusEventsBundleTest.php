<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Tests\Unit;

use Emagma\McpSyliusEventsBundle\EmagmaMcpSyliusEventsBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(EmagmaMcpSyliusEventsBundle::class)]
final class EmagmaMcpSyliusEventsBundleTest extends TestCase
{
    public function testItsAliasIsDerivedFromItsVendorPrefixedName(): void
    {
        self::assertSame('emagma_mcp_sylius_events', $this->extension()->getAlias());
    }

    public function testTheWebhookSyliusIsSignedWithTheSharedSecret(): void
    {
        self::assertSame([[
            'webhook' => ['routing' => ['sylius' => [
                'service' => 'emagma_mcp_sylius_events.request_parser',
                'secret' => '%env(MCP_EVENT_WEBHOOK_SECRET)%',
            ]]],
        ]], $this->prepend('framework')->getExtensionConfig('framework'));
    }

    public function testTheEntityIsMappedByAttributesForTheHost(): void
    {
        $mapping = $this->prepend('doctrine')->getExtensionConfig('doctrine')[0]['orm']['mappings']['EmagmaMcpSyliusEventsBundle'] ?? null;

        self::assertIsArray($mapping);
        self::assertSame('attribute', $mapping['type']);
        self::assertSame('Emagma\\McpSyliusEventsBundle\\Entity', $mapping['prefix']);
        self::assertFileExists($mapping['dir'] . '/ShopEvent.php');
    }

    public function testItsMigrationsAreAddedToTheHostsWhenTheMigrationsBundleIsThere(): void
    {
        self::assertSame([], $this->prepend()->getExtensionConfig('doctrine_migrations'));

        $paths = $this->prepend('doctrine_migrations')->getExtensionConfig('doctrine_migrations')[0]['migrations_paths'] ?? null;
        self::assertIsArray($paths);
        self::assertDirectoryExists($paths['Emagma\\McpSyliusEventsBundle\\Migrations']);
    }

    private function extension(): ExtensionInterface&PrependExtensionInterface
    {
        $extension = (new EmagmaMcpSyliusEventsBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);

        return $extension;
    }

    private function prepend(string ...$hostExtensions): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
        foreach ($hostExtensions as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(
                    private readonly string $alias,
                ) {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }
            });
        }
        $this->extension()->prepend($container);

        return $container;
    }
}
