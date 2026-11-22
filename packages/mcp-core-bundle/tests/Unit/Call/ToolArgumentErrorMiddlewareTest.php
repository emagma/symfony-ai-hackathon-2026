<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Call;

use Emagma\McpCoreBundle\Call\ToolArgumentErrorMiddleware;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolArgumentErrorMiddleware::class)]
final class ToolArgumentErrorMiddlewareTest extends TestCase
{
    public function testASchemaValidationErrorBecomesAToolErrorTheModelReads(): void
    {
        $error = Error::forInvalidParams("Invalid parameters for tool 'search_orders': Property '/paymentState/1': Value must be one of the allowed values: \"paid\"", 7, ['validation_errors' => [['pointer' => '/paymentState/1']]]);

        $result = ToolArgumentErrorMiddleware::asToolResult($error);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(7, $result->id);
        $call = $result->result;
        self::assertInstanceOf(CallToolResult::class, $call);
        self::assertTrue($call->isError);
        $content = $call->content[0];
        self::assertInstanceOf(TextContent::class, $content);
        self::assertStringContainsString("Property '/paymentState/1'", (string) $content->text);
        self::assertStringEndsWith('call the tool again.', (string) $content->text);
    }

    public function testEveryOtherErrorAndEveryResultIsLeftAlone(): void
    {
        $unknownTool = Error::forMethodNotFound('Tool not found: get_secrets', 3);
        $thrown = Error::forInvalidParams('Invalid argument', 4);
        $ok = new Response(5, new CallToolResult([new TextContent('{}')]));

        self::assertSame($unknownTool, ToolArgumentErrorMiddleware::asToolResult($unknownTool), 'a hidden tool stays hidden');
        self::assertSame($thrown, ToolArgumentErrorMiddleware::asToolResult($thrown), 'only schema validation carries validation_errors');
        self::assertSame($ok, ToolArgumentErrorMiddleware::asToolResult($ok));
    }
}
