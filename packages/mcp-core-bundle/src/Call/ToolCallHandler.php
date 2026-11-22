<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Call;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes `tools/call` over from the SDK's built-in handler and delegates to a stock one behind the middleware chain.
 *
 * @implements RequestHandlerInterface<CallToolResult|InputRequiredResult>
 *
 * @internal
 */
final class ToolCallHandler implements RequestHandlerInterface
{
    private readonly CallToolHandler $inner;

    /** @var list<ToolCallMiddlewareInterface> */
    private readonly array $middlewares;

    /**
     * @param iterable<ToolCallMiddlewareInterface> $middlewares outermost first
     */
    public function __construct(
        RegistryInterface $registry,
        iterable $middlewares = [],
        ?ContainerInterface $toolLocator = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->inner = null === $logger
            ? new CallToolHandler($registry, new ReferenceHandler($toolLocator))
            : new CallToolHandler($registry, new ReferenceHandler($toolLocator), $logger);
        $this->middlewares = array_values([...$middlewares]);
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /**
     * @return Response<CallToolResult|InputRequiredResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $inner = $this->inner;
        $next = static fn (CallToolRequest $request, SessionInterface $session): Response|Error => $inner->handle($request, $session);
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = static fn (CallToolRequest $request, SessionInterface $session): Response|Error => $middleware->handle($request, $session, $next);
        }

        return $next($request, $session);
    }
}
