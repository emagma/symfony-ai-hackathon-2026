<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Controller;

use Emagma\SyliusAssistantTestChat\Auth\AuthorizationServerDiscovery;
use Emagma\SyliusAssistantTestChat\Auth\OAuthClient;
use Emagma\SyliusAssistantTestChat\Auth\TokenSession;
use Emagma\SyliusAssistantTestChat\Auth\UserToken;
use Emagma\SyliusAssistantTestChat\Controller\OAuthController;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(OAuthController::class)]
final class OAuthControllerTest extends TestCase
{
    private Session $session;

    private OAuthController $controller;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());

        // Discovery answers; the token endpoint is unreachable on purpose: a
        // ConnectException proves the code exchange was attempted.
        $discovery = new MockHttpClient(static fn (string $method, string $url): JsonMockResponse => str_contains($url, 'oauth-protected-resource')
            ? new JsonMockResponse(['authorization_servers' => ['http://keycloak:8085/realms/sylius']])
            : new JsonMockResponse([
                'issuer' => 'http://keycloak:8085/realms/sylius',
                'authorization_endpoint' => 'http://keycloak:8085/auth',
                'token_endpoint' => 'http://127.0.0.1:9/token',
                'userinfo_endpoint' => 'http://keycloak:8085/userinfo',
            ]));
        $oauth = new OAuthClient(new AuthorizationServerDiscovery($discovery, new ArrayAdapter(), 'http://shop/_mcp'), 'http://localhost:8090');
        $this->controller = new OAuthController($oauth, new TokenSession($oauth));
    }

    /**
     * Chrome preloading a typed URL loads "/" twice: two logins start, each
     * with its own state, and Keycloak returns with either. Both must be valid.
     */
    public function testTwoLoginsInFlightBothKeepAValidState(): void
    {
        $first = $this->stateOf($this->controller->login($this->request('/login')));
        $this->controller->login($this->request('/login'));

        $this->expectException(ConnectException::class);
        $this->controller->callback($this->request('/oauth/callback', ['state' => $first, 'code' => 'abc']));
    }

    /**
     * A state that matches no login in flight is never exchanged. With other
     * logins pending it lost a race: start over.
     */
    public function testAnUnknownStateRestartsTheLoginWithoutExchangingTheCode(): void
    {
        $this->controller->login($this->request('/login'));

        $response = $this->controller->callback($this->request('/oauth/callback', ['state' => 'forged', 'code' => 'stolen']));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }

    /**
     * F5 on the callback once logged in: nothing to redo.
     */
    public function testAReplayedCallbackOnceLoggedInGoesToTheChat(): void
    {
        $this->session->set('testchat.user_token', new UserToken('jwt', 'refresh', time() + 300, 'admin', null));

        $response = $this->controller->callback($this->request('/oauth/callback', ['state' => 'already-used', 'code' => 'abc']));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->getTargetUrl());
    }

    /**
     * No login in flight at all: the session cookie did not survive the round
     * trip. Restarting would loop - say so instead.
     */
    public function testAnUnknownStateWithNoLoginInFlightIsAnError(): void
    {
        $response = $this->controller->callback($this->request('/oauth/callback', ['state' => 'whatever', 'code' => 'abc']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function testAnErrorDescriptionFromTheQueryIsNeverRenderedAsHtml(): void
    {
        $response = $this->controller->callback($this->request('/oauth/callback', ['error' => 'access_denied', 'error_description' => '<script>alert(1)</script>']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $path, array $query = []): Request
    {
        $request = Request::create($path, 'GET', $query);
        $request->setSession($this->session);

        return $request;
    }

    private function stateOf(RedirectResponse $response): string
    {
        parse_str((string) parse_url($response->getTargetUrl(), \PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        return $query['state'];
    }
}
