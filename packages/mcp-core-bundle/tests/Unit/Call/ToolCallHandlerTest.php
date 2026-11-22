<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Call;

use Emagma\McpCoreBundle\Call\ToolArgumentErrorMiddleware;
use Emagma\McpCoreBundle\Call\ToolCallHandler;
use Emagma\McpCoreBundle\Call\ToolCallMiddlewareInterface;
use Mcp\Capability\Registry;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolCallHandler::class)]
final class ToolCallHandlerTest extends TestCase
{
    public function testItTakesToolCallsOnly(): void
    {
        $handler = new ToolCallHandler(new Registry());

        self::assertTrue($handler->supports(new CallToolRequest('echo', [])));
        self::assertFalse($handler->supports(new ListToolsRequest()));
    }

    public function testWithoutMiddlewareTheToolAnswersAsWithTheSdkHandler(): void
    {
        $result = $this->call(new ToolCallHandler($this->registry()), ['text' => 'hi']);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame('hi', $this->text($result));
    }

    public function testTheFirstMiddlewareIsTheOutermost(): void
    {
        $log = new \ArrayObject();
        $handler = new ToolCallHandler($this->registry(), [$this->recording('outer', $log), $this->recording('inner', $log)]);

        $this->call($handler, ['text' => 'hi']);

        self::assertSame(['outer:before', 'inner:before', 'inner:after', 'outer:after'], $log->getArrayCopy());
    }

    public function testAMiddlewareCanAnswerInsteadOfTheTool(): void
    {
        $refusing = new class() implements ToolCallMiddlewareInterface {
            public function handle(CallToolRequest $request, SessionInterface $session, callable $next): Response|Error
            {
                return new Response($request->getId(), CallToolResult::error([new TextContent('not now')]));
            }
        };
        $log = new \ArrayObject();

        $result = $this->call(new ToolCallHandler($this->registry(), [$refusing, $this->recording('inner', $log)]), ['text' => 'hi']);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame('not now', $this->text($result));
        self::assertCount(0, $log, 'what follows a middleware that answered does not run');
    }

    public function testAnArgumentTheSchemaRefusesComesBackAsAToolError(): void
    {
        $result = $this->call(new ToolCallHandler($this->registry(), [new ToolArgumentErrorMiddleware()]), ['text' => 12]);

        self::assertInstanceOf(Response::class, $result);
        self::assertInstanceOf(CallToolResult::class, $result->result);
        self::assertTrue($result->result->isError);
        self::assertStringEndsWith('call the tool again.', $this->text($result));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(ToolCallHandler $handler, array $arguments): Response|Error
    {
        return $handler->handle((new CallToolRequest('echo', $arguments))->withId(7), $this->createStub(SessionInterface::class));
    }

    private function registry(): Registry
    {
        $registry = new Registry();
        $registry->registerTool(
            new Tool('echo', null, ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']], null, null),
            static fn (string $text): string => $text,
        );

        return $registry;
    }

    /**
     * @param \ArrayObject<int, string> $log
     */
    private function recording(string $name, \ArrayObject $log): ToolCallMiddlewareInterface
    {
        return new class($name, $log) implements ToolCallMiddlewareInterface {
            /**
             * @param \ArrayObject<int, string> $log
             */
            public function __construct(private readonly string $name, private readonly \ArrayObject $log)
            {
            }

            public function handle(CallToolRequest $request, SessionInterface $session, callable $next): Response|Error
            {
                $this->log->append($this->name . ':before');
                $result = $next($request, $session);
                $this->log->append($this->name . ':after');

                return $result;
            }
        };
    }

    /**
     * @param Response<mixed> $response
     */
    private function text(Response $response): string
    {
        self::assertInstanceOf(CallToolResult::class, $response->result);
        $content = $response->result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);

        return (string) $content->text;
    }
}
