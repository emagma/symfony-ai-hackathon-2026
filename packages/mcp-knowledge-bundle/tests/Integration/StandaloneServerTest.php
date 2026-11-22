<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Tests\Integration;

use Emagma\McpCoreBundle\Call\ToolCallHandler;
use Emagma\McpKnowledgeBundle\Command\IndexKnowledgeCommand;
use Emagma\McpKnowledgeBundle\Index\KnowledgeIndex;
use Emagma\McpKnowledgeBundle\Tests\Integration\Fixture\TestKernel;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A real compiled kernel: the core and the knowledge bundle, nothing else.
 */
#[CoversNothing]
final class StandaloneServerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(TestKernel::varDir());
    }

    public function testTheModelFindsAProcedureThroughTheToolChain(): void
    {
        $container = $this->container();
        $container->get('emagma_mcp_core.server');
        $handler = $container->get('emagma_mcp_core.tool_call_handler');
        self::assertInstanceOf(ToolCallHandler::class, $handler);

        $response = $handler->handle(
            (new CallToolRequest('search_knowledge', ['question' => 'lost parcel claim carrier']))->withId(1),
            $this->createStub(SessionInterface::class),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
        $content = $response->result->content[0];
        self::assertInstanceOf(TextContent::class, $content);
        self::assertStringContainsString('"title": "Lost parcel"', $content->text);
        self::assertStringContainsString('"mode": "keywords"', $content->text);
    }

    public function testTheIndexAndTheCommandShareTheSameStore(): void
    {
        $container = $this->container();
        $index = $container->get('test.knowledge_index');
        $command = $container->get('test.knowledge_command');
        self::assertInstanceOf(KnowledgeIndex::class, $index);
        self::assertInstanceOf(IndexKnowledgeCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute(['--rebuild' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 file(s) indexed', $tester->getDisplay());
        self::assertSame(4, $index->count());
        self::assertFileExists(TestKernel::varDir() . '/index.sqlite');
        self::assertFileExists(TestKernel::varDir() . '/index.sqlite.manifest.json');
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
