<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit;

use Emagma\McpAgentsBundle\EmagmaMcpAgentsBundle;
use Emagma\McpAgentsBundle\Schedule\RunAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(EmagmaMcpAgentsBundle::class)]
final class EmagmaMcpAgentsBundleTest extends TestCase
{
    public function testItsAliasIsDerivedFromItsVendorPrefixedName(): void
    {
        self::assertSame('emagma_mcp_agents', $this->extension()->getAlias());
    }

    public function testTheAgentsHaveATransportOfTheirOwnRetriedThenKeptOnFailure(): void
    {
        $container = $this->prepend('framework');

        self::assertSame([[
            'messenger' => [
                'transports' => [
                    'mcp_agents' => [
                        'dsn' => '%env(default:emagma_mcp_agents.transport_dsn:MCP_AGENTS_TRANSPORT_DSN)%',
                        'retry_strategy' => ['max_retries' => 3, 'delay' => 30000, 'multiplier' => 2],
                        'failure_transport' => 'failed_mcp_agents',
                    ],
                    'failed_mcp_agents' => ['dsn' => 'doctrine://default?queue_name=failed_mcp_agents'],
                ],
                'routing' => [RunAgent::class => 'mcp_agents'],
            ],
            'cache' => ['pools' => ['emagma_mcp_agents.schedule' => ['adapter' => 'cache.adapter.doctrine_dbal']]],
        ]], $container->getExtensionConfig('framework'));
        self::assertSame('doctrine://default?queue_name=mcp_agents', $container->getParameter('emagma_mcp_agents.transport_dsn'), 'MCP_AGENTS_TRANSPORT_DSN not set, or empty');
    }

    public function testTheHostKeepsItsOwnDefaultTransport(): void
    {
        $container = $this->container();
        $container->setParameter('emagma_mcp_agents.transport_dsn', 'redis://queue');

        $this->extension()->prepend($container);

        self::assertSame('redis://queue', $container->getParameter('emagma_mcp_agents.transport_dsn'));
    }

    public function testTheEntityIsMappedByAttributesForTheHost(): void
    {
        $mapping = $this->prepend('doctrine')->getExtensionConfig('doctrine')[0]['orm']['mappings']['EmagmaMcpAgentsBundle'] ?? null;

        self::assertIsArray($mapping);
        self::assertSame('attribute', $mapping['type']);
        self::assertSame('Emagma\\McpAgentsBundle\\Entity', $mapping['prefix']);
        self::assertFileExists($mapping['dir'] . '/AgentReport.php');
    }

    public function testItsMigrationsAreAddedToTheHostsWhenTheMigrationsBundleIsThere(): void
    {
        self::assertSame([], $this->prepend()->getExtensionConfig('doctrine_migrations'));

        $paths = $this->prepend('doctrine_migrations')->getExtensionConfig('doctrine_migrations')[0]['migrations_paths'] ?? null;
        self::assertIsArray($paths);
        self::assertDirectoryExists($paths['Emagma\\McpAgentsBundle\\Migrations']);
    }

    private function extension(): ExtensionInterface&PrependExtensionInterface
    {
        $extension = (new EmagmaMcpAgentsBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);

        return $extension;
    }

    private function prepend(string ...$hostExtensions): ContainerBuilder
    {
        $container = $this->container();
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

    private function container(): ContainerBuilder
    {
        return new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
    }
}
