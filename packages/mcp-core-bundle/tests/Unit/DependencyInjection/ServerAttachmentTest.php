<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\DependencyInjection;

use Emagma\McpCoreBundle\DependencyInjection\Compiler\HandshakeEraPass;
use Emagma\McpCoreBundle\DependencyInjection\Compiler\ServerBuilder;
use Emagma\McpCoreBundle\DependencyInjection\Compiler\ToolCallLocatorPass;
use Emagma\McpCoreBundle\DependencyInjection\Compiler\ToolClassMapPass;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(EmagmaMcpCoreBundle::class)]
#[CoversClass(ServerBuilder::class)]
#[CoversClass(HandshakeEraPass::class)]
#[CoversClass(ToolCallLocatorPass::class)]
#[CoversClass(ToolClassMapPass::class)]
final class ServerAttachmentTest extends TestCase
{
    public function testItAttachesToTheServerTheConfigurationNames(): void
    {
        $container = $this->load(['server' => 'shop']);

        self::assertSame('mcp.server.shop.registry', (string) $container->getAlias('emagma_mcp_core.registry'));
        self::assertSame('mcp.server.shop', (string) $container->getAlias('emagma_mcp_core.server'));
        self::assertSame(
            'mcp.server.shop.registry',
            $container->getDefinition('emagma_mcp_core.authorization_registry')->getDecoratedService()[0] ?? null,
            'the authorization decorates that server\'s registry',
        );
        self::assertSame('mcp.server.shop.builder', ServerBuilder::id($container));
    }

    public function testWithoutANameItIsTheServerCalledDefault(): void
    {
        self::assertSame('mcp.server.default.registry', (string) $this->load([])->getAlias('emagma_mcp_core.registry'));
    }

    public function testThePassesReachTheBuilderOfThatServer(): void
    {
        $container = $this->load(['server' => 'shop']);
        $container->setDefinition('mcp.server.shop.builder', new Definition());
        $container->setDefinition('mcp.server.other.builder', new Definition());

        (new HandshakeEraPass())->process($container);

        self::assertTrue($container->getDefinition('mcp.server.shop.builder')->hasMethodCall('withoutModernEra'));
        self::assertFalse($container->getDefinition('mcp.server.other.builder')->hasMethodCall('withoutModernEra'));
    }

    public function testTheToolCallHandlerResolvesToolsFromTheLocatorMcpBundleBuilt(): void
    {
        $container = $this->load(['server' => 'shop']);
        $locator = new Reference('.service_locator.tools');
        $container->setDefinition('mcp.server.shop.builder', (new Definition())->addMethodCall('setContainer', [$locator]));

        (new ToolCallLocatorPass())->process($container);

        self::assertSame($locator, $container->getDefinition('emagma_mcp_core.tool_call_handler')->getArgument(2));
        self::assertSame($locator, $container->getDefinition('emagma_mcp_core.local_tool_caller')->getArgument(1), 'a tool called from a worker is fetched too');
    }

    public function testTheToolsOfThatServerAreKnownByName(): void
    {
        $container = $this->load(['server' => 'shop']);
        $container->setDefinition('mcp.server.shop.builder', (new Definition())->addMethodCall('addTool', [[ServerBuilder::class, '__invoke'], 'echo']));

        (new ToolClassMapPass())->process($container);

        self::assertSame(['echo' => ServerBuilder::class], $container->getDefinition('emagma_mcp_core.tool_class_map')->getArgument(0));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
        $extension = (new EmagmaMcpCoreBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
