<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Mcp;

use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The PSR-18 client of the MCP connection (config/packages/mcp.yaml).
 *
 * Adds what static `headers:` cannot: the bearer of the user running the turn,
 * and the W3C traceparent of the current span so the server's spans join the
 * turn's trace.
 *
 * Timeouts, in this order: the UI confirmation (30 s) < the MCP request
 * timeout (50 s) < this client (55 s idle, 58 s total) < the shop's PHP
 * max_execution_time (60 s). A tools/call that waits for a human holds one SSE
 * response open for as long as the confirmation takes.
 */
final class McpHttpClient implements ClientInterface
{
    private readonly ClientInterface $inner;

    public function __construct(
        private readonly TurnContext $turn,
        ?ClientInterface $inner = null,
    ) {
        $this->inner = $inner ?? new Psr18Client(HttpClient::create(['timeout' => 55, 'max_duration' => 58]));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $bearer = $this->turn->bearer();
        if (null === $bearer) {
            throw new \LogicException('No user token for this MCP request: start the turn with TurnContext::start().');
        }

        $request = $request->withHeader('Authorization', 'Bearer ' . $bearer);

        $traceparent = $this->turn->traceparent();
        if (null !== $traceparent) {
            $request = $request->withHeader('traceparent', $traceparent);
        }

        return $this->inner->sendRequest($request);
    }
}
