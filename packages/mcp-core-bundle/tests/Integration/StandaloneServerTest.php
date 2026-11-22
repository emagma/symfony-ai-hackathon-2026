<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Integration;

use Emagma\McpCoreBundle\Authorization\ToolAuthorizationRegistry;
use Emagma\McpCoreBundle\Call\ToolCallHandler;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Command\ListPermissionsCommand;
use Emagma\McpCoreBundle\Tests\Integration\Fixture\EchoTool;
use Emagma\McpCoreBundle\Tests\Integration\Fixture\TestKernel;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A real compiled kernel: the bundle holds together without any of the bundles built on it.
 */
#[CoversNothing]
final class StandaloneServerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(TestKernel::varDir());
    }

    public function testItIsClosedUntilTheHostSaysWhoMayCallWhat(): void
    {
        $container = $this->boot(allowAll: false);
        $registry = $container->get('emagma_mcp_core.registry');

        self::assertInstanceOf(ToolAuthorizationRegistry::class, $registry, 'the authorization decorates the server\'s registry');
        self::assertSame([], $registry->getTools()->references, 'deny-all by default');
        self::assertFalse($registry->hasTool('echo'));
    }

    public function testItsToolIsRegisteredAndDocumentedWhateverTheGrants(): void
    {
        $container = $this->boot(allowAll: false);
        $command = $container->get('emagma_mcp_core.command.list_permissions');
        self::assertInstanceOf(ListPermissionsCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute(['--format' => 'json']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('mcp:standalone:echo:read', $tester->getDisplay());
        self::assertStringContainsString('mcp:standalone:echo_all:read', $tester->getDisplay(), 'a widening is a grant to mint too');
    }

    public function testTheToolsAreKnownByNameWithoutBuildingTheServer(): void
    {
        $kernel = new TestKernel(false);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        $map = $container->get('test.tool_class_map');
        self::assertInstanceOf(ToolClassMap::class, $map);
        self::assertSame(EchoTool::class, $map->classOf('echo'));
    }

    public function testAnAllowedCallerListsAndCallsTheToolThroughTheChain(): void
    {
        $container = $this->boot(allowAll: true);
        $registry = $container->get('emagma_mcp_core.registry');
        self::assertInstanceOf(RegistryInterface::class, $registry);

        self::assertSame(['echo'], array_map(static fn (Tool $tool): string => $tool->name, $registry->getTools()->references));

        $handler = $container->get('emagma_mcp_core.tool_call_handler');
        self::assertInstanceOf(ToolCallHandler::class, $handler);
        $response = $handler->handle((new CallToolRequest('echo', ['text' => 'hello']))->withId(1), $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);

        $refused = $handler->handle((new CallToolRequest('echo', ['text' => 12]))->withId(2), $this->createStub(SessionInterface::class));
        self::assertInstanceOf(Response::class, $refused);
        self::assertInstanceOf(CallToolResult::class, $refused->result);
        self::assertTrue($refused->result->isError);
    }

    private function boot(bool $allowAll): ContainerInterface
    {
        $kernel = new TestKernel($allowAll);
        $kernel->boot();

        // The registry is empty until the server's Builder has run.
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $container->get('emagma_mcp_core.server');

        return $container;
    }
}
