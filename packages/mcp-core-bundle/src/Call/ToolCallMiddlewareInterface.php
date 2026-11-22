<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Call;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Session\SessionInterface;

/**
 * Extension point (tag `emagma_mcp_core.tool_call_middleware`, highest priority outermost): one layer around every `tools/call`.
 * A tool that elicits suspends the Fiber inside `$next`: a middleware that must see each suspension runs `$next` in a Fiber of its own.
 */
interface ToolCallMiddlewareInterface
{
    /**
     * @param callable(CallToolRequest, SessionInterface): (Response<CallToolResult|InputRequiredResult>|Error) $next
     *
     * @return Response<CallToolResult|InputRequiredResult>|Error
     */
    public function handle(CallToolRequest $request, SessionInterface $session, callable $next): Response|Error;
}
