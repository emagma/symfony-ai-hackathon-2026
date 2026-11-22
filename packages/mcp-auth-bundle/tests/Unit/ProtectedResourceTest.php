<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\Tests\Unit;

use Emagma\McpAuthBundle\Controller\OAuthMetadataController;
use Emagma\McpAuthBundle\EventListener\McpChallengeResponseListener;
use Emagma\McpAuthBundle\McpAuthenticationEntryPoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(McpAuthenticationEntryPoint::class)]
#[CoversClass(McpChallengeResponseListener::class)]
#[CoversClass(OAuthMetadataController::class)]
final class ProtectedResourceTest extends TestCase
{
    private const string POINTER = 'resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"';

    public function testARequestWithoutATokenIsPointedAtTheMetadata(): void
    {
        $response = (new McpAuthenticationEntryPoint())->start(Request::create('https://mcp.example.com/_mcp', 'POST'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer ' . self::POINTER, $response->headers->get('WWW-Authenticate'));
    }

    public function testA401OfTheEndpointThatLacksThePointerGetsIt(): void
    {
        $invalidToken = new Response('', 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);

        $this->listen('/_mcp', $invalidToken);

        self::assertSame('Bearer error="invalid_token", ' . self::POINTER, $invalidToken->headers->get('WWW-Authenticate'));
    }

    public function testA401WithNoChallengeAtAllGetsOne(): void
    {
        $bare = new Response('', 401);

        $this->listen('/_mcp', $bare);

        self::assertSame('Bearer ' . self::POINTER, $bare->headers->get('WWW-Authenticate'));
    }

    public function testTheEntryPointsOwnChallengeIsLeftAlone(): void
    {
        $response = (new McpAuthenticationEntryPoint())->start(Request::create('https://mcp.example.com/_mcp', 'POST'));

        $this->listen('/_mcp', $response);

        self::assertSame('Bearer ' . self::POINTER, $response->headers->get('WWW-Authenticate'));
    }

    public function testOnlyThe401sOfTheEndpointAreTouched(): void
    {
        $elsewhere = new Response('', 401);
        $this->listen('/login', $elsewhere);
        self::assertFalse($elsewhere->headers->has('WWW-Authenticate'));

        $forbidden = new Response('', 403);
        $this->listen('/_mcp', $forbidden);
        self::assertFalse($forbidden->headers->has('WWW-Authenticate'));

        $moved = new Response('', 401);
        $this->listen('/_mcp', $moved, resourcePath: '/mcp');
        self::assertFalse($moved->headers->has('WWW-Authenticate'), 'the endpoint is where emagma_mcp_auth.resource_path says');
    }

    public function testTheMetadataNamesTheResourceAndItsAuthorizationServers(): void
    {
        $controller = new OAuthMetadataController(['https://idp.example.com/realms/shop'], '/_mcp', ['openid']);

        $response = $controller->protectedResource(Request::create('https://mcp.example.com/.well-known/oauth-protected-resource'));

        self::assertSame(
            [
                'resource' => 'https://mcp.example.com/_mcp',
                'authorization_servers' => ['https://idp.example.com/realms/shop'],
                'scopes_supported' => ['openid'],
                'bearer_methods_supported' => ['header'],
            ],
            json_decode((string) $response->getContent(), true),
        );
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'), 'discovery is cross-origin for browser clients');
    }

    private function listen(string $path, Response $response, string $resourcePath = '/_mcp'): void
    {
        (new McpChallengeResponseListener($resourcePath))->onResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('https://mcp.example.com' . $path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));
    }
}
