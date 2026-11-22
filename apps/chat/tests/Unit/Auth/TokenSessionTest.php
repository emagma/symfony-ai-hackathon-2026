<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Auth;

use Emagma\SyliusAssistantTestChat\Auth\AuthorizationServerDiscovery;
use Emagma\SyliusAssistantTestChat\Auth\OAuthClient;
use Emagma\SyliusAssistantTestChat\Auth\TokenSession;
use Emagma\SyliusAssistantTestChat\Auth\UserToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(TokenSession::class)]
#[CoversClass(UserToken::class)]
final class TokenSessionTest extends TestCase
{
    public function testAFreshTokenIsReturnedAsIs(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $token = new UserToken('access', 'refresh', time() + 300, 'admin', null);

        $tokens = new TokenSession($this->oauthThatMustNotBeCalled());
        $tokens->store($session, $token);

        self::assertSame($token, $tokens->fresh($session));
    }

    /**
     * A turn releases the session before streaming and cannot write it back:
     * a token about to expire is renewed before the turn starts. When the
     * renewal fails (Keycloak session over), the user is logged out.
     */
    public function testATokenAboutToExpireIsRenewedOrTheUserLoggedOut(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $tokens = new TokenSession($this->oauthThatMustNotBeCalled());
        $tokens->store($session, new UserToken('access', 'refresh', time() + 30, 'admin', null));

        self::assertNull($tokens->fresh($session));
        self::assertNull($tokens->get($session));
    }

    public function testNoSessionMeansNoToken(): void
    {
        self::assertNull((new TokenSession($this->oauthThatMustNotBeCalled()))->fresh(new Session(new MockArraySessionStorage())));
    }

    /**
     * An OAuth client whose every HTTP call fails: enough to prove which paths
     * reach Keycloak and that a failure is handled.
     */
    private function oauthThatMustNotBeCalled(): OAuthClient
    {
        $failing = new MockHttpClient(static fn (): never => throw new \RuntimeException('no network in unit tests'));

        return new OAuthClient(new AuthorizationServerDiscovery($failing, new ArrayAdapter(), 'http://shop/_mcp'), 'http://localhost:8090');
    }
}
