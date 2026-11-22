<?php

declare(strict_types=1);

namespace Emagma\McpSkillsBundle\Tests\Integration;

use Emagma\McpCoreBundle\Call\ToolCallHandler;
use Emagma\McpSkillsBundle\SkillRepository;
use Emagma\McpSkillsBundle\Tests\Integration\Fixture\TestKernel;
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
 * A real compiled kernel: the core and the skills bundle, nothing else.
 */
#[CoversNothing]
final class StandaloneServerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(TestKernel::varDir());
    }

    public function testTheRepositoryReadsTheConfiguredDirectory(): void
    {
        $repository = $this->container()->get('test.skill_repository');

        self::assertInstanceOf(SkillRepository::class, $repository);
        self::assertSame(['stock-check'], array_keys($repository->all()));
    }

    public function testTheModelListsTheSkillsThroughTheToolChainWithTheConfiguredPack(): void
    {
        $answer = json_decode($this->call('list_skills', []), true);

        self::assertIsArray($answer);
        self::assertSame('shop', $answer['pack']);
        self::assertSame('skill://shop/stock-check/SKILL.md', $answer['items'][0]['uri']);
    }

    public function testTheModelGetsOneSkillThroughTheToolChain(): void
    {
        $answer = json_decode($this->call('get_skill', ['name' => 'stock-check']), true);

        self::assertIsArray($answer);
        self::assertTrue($answer['found']);
        self::assertSame(['SKILL.md', 'thresholds.md'], $answer['files']);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(string $tool, array $arguments): string
    {
        $container = $this->container();
        $container->get('emagma_mcp_core.server');
        $handler = $container->get('emagma_mcp_core.tool_call_handler');
        self::assertInstanceOf(ToolCallHandler::class, $handler);

        $response = $handler->handle((new CallToolRequest($tool, $arguments))->withId(1), $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
        $content = $response->result->content[0];
        self::assertInstanceOf(TextContent::class, $content);

        return $content->text;
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
