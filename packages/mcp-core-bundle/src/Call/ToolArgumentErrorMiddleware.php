<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Call;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Session\SessionInterface;

/**
 * Turns the SDK's schema-validation error (-32602) into an `isError` result the model can correct.
 * An unknown or forbidden tool stays a protocol error: that is what hides a tool the caller may not see.
 *
 * @internal
 */
final class ToolArgumentErrorMiddleware implements ToolCallMiddlewareInterface
{
    public function handle(CallToolRequest $request, SessionInterface $session, callable $next): Response|Error
    {
        return self::asToolResult($next($request, $session));
    }

    /**
     * @param Response<CallToolResult|InputRequiredResult>|Error $result
     *
     * @return Response<CallToolResult|InputRequiredResult>|Error
     */
    public static function asToolResult(Response|Error $result): Response|Error
    {
        if (!$result instanceof Error || null === $result->id || Error::INVALID_PARAMS !== $result->code || !\is_array($result->data) || !isset($result->data['validation_errors'])) {
            return $result;
        }

        return new Response($result->id, CallToolResult::error([
            new TextContent($result->message . '. Fix these arguments and call the tool again.'),
        ]));
    }
}
