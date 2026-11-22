<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Mcp;

use Emagma\SyliusAssistantTestChat\Mcp\McpHttpClient;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(McpHttpClient::class)]
final class McpHttpClientTest extends TestCase
{
    public function testEveryMcpRequestCarriesTheUsersBearerAndTheTurnsTraceparent(): void
    {
        $inner = new class() implements ClientInterface {
            public ?RequestInterface $sent = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent = $request;

                return new Response(200);
            }
        };

        $turn = new TurnContext();
        $turn->start('user-jwt');
        $turn->setTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        (new McpHttpClient($turn, $inner))->sendRequest(new Request('POST', 'http://shop/_mcp'));

        self::assertNotNull($inner->sent);
        self::assertSame('Bearer user-jwt', $inner->sent->getHeaderLine('Authorization'));
        self::assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $inner->sent->getHeaderLine('traceparent'));
    }

    public function testNoTurnNoRequest(): void
    {
        $this->expectException(\LogicException::class);

        (new McpHttpClient(new TurnContext(), $this->createStub(ClientInterface::class)))->sendRequest(new Request('POST', 'http://shop/_mcp'));
    }
}
