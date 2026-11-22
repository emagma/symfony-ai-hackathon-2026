<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Tests\Integration;

use Emagma\McpCoreBundle\Call\ToolCallHandler;
use Emagma\McpCoreBundle\Instruction\McpInstructions;
use Emagma\McpGuidelinesBundle\EmagmaMcpGuidelinesBundle;
use Emagma\McpGuidelinesBundle\GuidelineResolver;
use Emagma\McpGuidelinesBundle\Tests\Integration\Fixture\TestKernel;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A real compiled kernel: the core and the guidelines bundle, nothing else.
 */
#[CoversNothing]
final class StandaloneServerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(TestKernel::varDir());
    }

    public function testTheServersInstructionsListTheIntents(): void
    {
        $instructions = $this->container()->get('emagma_mcp_core.instructions');
        self::assertInstanceOf(McpInstructions::class, $instructions);

        $text = $instructions->text();

        self::assertStringContainsString('you MUST call get_guidelines(intents: [...])', $text);
        self::assertStringContainsString('- product_description: Product written content', $text);
        self::assertStringContainsString('- customer_reply: Writing to a customer', $text);
    }

    public function testTheModelGetsTheGuidelinesOfAnIntentThroughTheToolChain(): void
    {
        $container = $this->container();
        $container->get('emagma_mcp_core.server');
        $handler = $container->get('emagma_mcp_core.tool_call_handler');
        self::assertInstanceOf(ToolCallHandler::class, $handler);

        $response = $handler->handle((new CallToolRequest('get_guidelines', ['intents' => ['product_description']]))->withId(1), $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
        $content = $response->result->content[0];
        self::assertInstanceOf(TextContent::class, $content);
        self::assertStringContainsString('"name": "company"', $content->text);
        self::assertStringContainsString('"name": "catalog_content"', $content->text);
        self::assertStringNotContainsString('customer_message', $content->text);
    }

    public function testTheShippedGuidelinesAreReadFromTheBundle(): void
    {
        $resolver = $this->container()->get('test.guidelines_resolver');
        self::assertInstanceOf(GuidelineResolver::class, $resolver);

        self::assertSame(
            EmagmaMcpGuidelinesBundle::DEFAULTS,
            array_keys($resolver->all()),
        );
    }

    private function container(): ContainerInterface
    {
        $kernel = new TestKernel();
        $kernel->boot();

        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
