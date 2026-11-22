<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit;

use Emagma\McpTasksBundle\Controller\AuthorizeController;
use Emagma\McpTasksBundle\Delegation\DelegationRevoked;
use Emagma\McpTasksBundle\Delegation\OfflineTokenDelegation;
use Emagma\McpTasksBundle\Delegation\TokenVault;
use Emagma\McpTasksBundle\Testing\InMemoryTokenVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

#[CoversClass(OfflineTokenDelegation::class)]
#[CoversClass(AuthorizeController::class)]
#[CoversClass(TokenVault::class)]
final class OfflineTokenDelegationTest extends TestCase
{
    private InMemoryTokenVault $vault;

    private TokenStorage $tokens;

    private MockClock $clock;

    /** @var list<array<string, string>> the forms posted to the token endpoint */
    private array $posted = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    /** @var list<string> the access tokens the server validated */
    private array $validated = [];

    protected function setUp(): void
    {
        $this->vault = new InMemoryTokenVault();
        $this->tokens = new TokenStorage();
        $this->clock = new MockClock('2026-11-16 10:00:00');
    }

    public function testTheLinkCarriesAStateOnlyItsOwnerCanUse(): void
    {
        $delegation = $this->delegation();
        self::assertFalse($delegation->isAuthorized('alice'));

        parse_str((string) parse_url($delegation->authorizationUrl('alice'), \PHP_URL_QUERY), $query);
        self::assertSame('mcp-tasks', $query['client_id']);
        self::assertSame('openid offline_access', $query['scope']);
        self::assertSame('https://mcp.example/tasks/authorize/callback', $query['redirect_uri']);
        self::assertIsString($query['state']);

        // The identity provider answers with a token of bob: the state was alice's.
        $this->responses[] = self::tokens('access-of-bob', 'offline-of-bob');
        self::assertFalse($delegation->complete('the-code', $query['state']));
        self::assertFalse($delegation->isAuthorized('alice'));
        self::assertFalse($delegation->isAuthorized('bob'));

        $this->responses[] = self::tokens('access-of-alice', 'offline-of-alice');
        self::assertTrue($delegation->complete('the-code', $query['state']));
        self::assertTrue($delegation->isAuthorized('alice'));
        self::assertSame('authorization_code', $this->posted[1]['grant_type']);
        self::assertNull($this->tokens->getToken());

        self::assertFalse($delegation->complete('the-code', 'forged.state'));
        $this->clock->sleep(3600);
        self::assertFalse($delegation->complete('the-code', $query['state']));
    }

    public function testTheCallbackPageSaysWhetherTheAuthorizationWasRecorded(): void
    {
        $delegation = $this->delegation();
        $controller = new AuthorizeController($delegation);
        parse_str((string) parse_url($delegation->authorizationUrl('alice'), \PHP_URL_QUERY), $query);
        self::assertIsString($query['state']);

        $refused = $controller(Request::create('/tasks/authorize/callback', 'GET', ['code' => 'the-code', 'state' => 'forged.state']));
        self::assertSame(400, $refused->getStatusCode());
        self::assertStringContainsString('not recorded', (string) $refused->getContent());

        $this->responses[] = self::tokens('access-of-alice', 'offline-of-alice');
        $recorded = $controller(Request::create('/tasks/authorize/callback', 'GET', ['code' => 'the-code', 'state' => $query['state']]));
        self::assertSame(200, $recorded->getStatusCode());
        self::assertStringContainsString('no-store', (string) $recorded->headers->get('Cache-Control'));
        self::assertTrue($delegation->isAuthorized('alice'));
        // The page checked a token through the firewall's handler: nothing of it stays.
        self::assertNull($this->tokens->getToken());
    }

    public function testTheWorkerActsAsTheOwnerWithAFreshToken(): void
    {
        $delegation = $this->delegation();
        $this->vault->store('alice', 'offline-of-alice');

        $this->responses[] = self::tokens('access-of-alice', 'offline-of-alice-2', 60);
        $delegation->actAs('alice');

        self::assertSame('alice', $this->tokens->getToken()?->getUserIdentifier());
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'offline-of-alice', 'client_id' => 'mcp-tasks', 'client_secret' => 's3cret'], $this->posted[0]);
        self::assertSame(['access-of-alice'], $this->validated);
        self::assertSame('offline-of-alice-2', $this->vault->fetch('alice'));

        // Still valid: the identity provider is not asked again.
        $delegation->refresh();
        self::assertCount(1, $this->posted);

        // Expired: the rights of the moment are read again.
        $this->clock->sleep(61);
        $this->responses[] = self::tokens('access-of-alice-2', 'offline-of-alice-2', 60);
        $delegation->refresh();
        self::assertSame(['access-of-alice', 'access-of-alice-2'], $this->validated);

        $delegation->release();
        self::assertNull($this->tokens->getToken());
    }

    public function testARevokedTokenStopsTheWorkerAndIsForgotten(): void
    {
        $delegation = $this->delegation();
        $this->vault->store('alice', 'offline-of-alice');
        $this->responses[] = new MockResponse('{"error": "invalid_grant"}', ['http_code' => 400]);

        try {
            $delegation->actAs('alice');
            self::fail('The delegation should have been refused.');
        } catch (DelegationRevoked) {
        }

        self::assertFalse($delegation->isAuthorized('alice'));
        self::assertNull($this->tokens->getToken());
    }

    public function testWithoutATokenTheWorkerDoesNotAct(): void
    {
        $this->expectException(DelegationRevoked::class);

        $this->delegation()->actAs('alice');
    }

    public function testRevokingForgetsTheToken(): void
    {
        $this->vault->store('alice', 'offline-of-alice');
        $this->delegation()->revoke('alice');

        self::assertNull($this->vault->fetch('alice'));
    }

    public function testTheVaultNeverHoldsTheTokenInClear(): void
    {
        self::assertSame('offline-of-alice', TokenVault::decrypt(TokenVault::encrypt('offline-of-alice', 'key'), 'key'));
        self::assertStringNotContainsString('offline', TokenVault::encrypt('offline-of-alice', 'key'));
        self::assertNull(TokenVault::decrypt(TokenVault::encrypt('offline-of-alice', 'key'), 'another key'));
    }

    private function delegation(): OfflineTokenDelegation
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str((string) $options['body'], $form);
            $this->posted[] = array_map(strval(...), $form);

            return array_shift($this->responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });
        $handler = new class($this->validated) implements AccessTokenHandlerInterface {
            /**
             * @param list<string> $validated
             */
            public function __construct(private array &$validated)
            {
            }

            public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
            {
                $this->validated[] = $accessToken;
                $sub = str_contains($accessToken, 'bob') ? 'bob' : 'alice';

                return new UserBadge($sub, static fn (): InMemoryUser => new InMemoryUser($sub, null));
            }
        };

        return new OfflineTokenDelegation(
            $this->vault,
            $http,
            $handler,
            $this->tokens,
            $this->clock,
            'https://idp.example/auth',
            'https://idp.example/token',
            'mcp-tasks',
            's3cret',
            'https://mcp.example/tasks/authorize/callback',
            'signing-secret',
        );
    }

    private static function tokens(string $access, string $refresh, int $expiresIn = 300): MockResponse
    {
        return new MockResponse((string) json_encode(['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $expiresIn]));
    }
}
