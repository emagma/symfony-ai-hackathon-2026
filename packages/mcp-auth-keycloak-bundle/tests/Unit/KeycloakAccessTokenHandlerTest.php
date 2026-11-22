<?php

declare(strict_types=1);

namespace Emagma\McpAuthKeycloakBundle\Tests\Unit;

use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpAuthKeycloakBundle\KeycloakAccessTokenHandler;
use Emagma\McpAuthKeycloakBundle\KeycloakUser;
use Emagma\McpAuthKeycloakBundle\Testing\FakeKeycloakHttpClient;
use Emagma\McpCoreBundle\Caller\McpCallerStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

#[CoversClass(KeycloakAccessTokenHandler::class)]
#[CoversClass(KeycloakUser::class)]
final class KeycloakAccessTokenHandlerTest extends TestCase
{
    public function testTheUserIsIdentifiedByItsSubjectAndKeepsItsEmail(): void
    {
        $handler = new KeycloakAccessTokenHandler(new FakeKeycloakHttpClient(), new McpGrantsTokenStore(), 'http://keycloak/userinfo');

        $badge = $handler->getUserBadgeFrom(FakeKeycloakHttpClient::token('jean@example.com', ['mcp:sylius:product:read']));
        $user = ($badge->getUserLoader() ?? static fn (): null => null)();

        $sub = FakeKeycloakHttpClient::subOf('jean@example.com');
        self::assertSame($sub, $badge->getUserIdentifier(), 'the identity Symfony keeps is the subject');
        self::assertInstanceOf(KeycloakUser::class, $user);
        self::assertSame($sub, $user->getUserIdentifier());
        self::assertSame('jean@example.com', $user->getEmail());
        self::assertNotSame($user->getUserIdentifier(), $user->getEmail());
    }

    public function testTheOAuthClientOfTheTokenIsStoredForThePrivacyMode(): void
    {
        $callers = new McpCallerStore();
        $handler = new KeycloakAccessTokenHandler(new FakeKeycloakHttpClient(), new McpGrantsTokenStore(), 'http://keycloak/userinfo', $callers);

        $handler->getUserBadgeFrom(FakeKeycloakHttpClient::token('jean@example.com', [], clientId: 'mcp-internal'));
        self::assertSame('mcp-internal', $callers->clientId());

        $handler->getUserBadgeFrom(FakeKeycloakHttpClient::token('jean@example.com', []));
        self::assertSame('mcp-llm', $callers->clientId(), 'the fake issues to mcp-llm by default, like the realm');
    }

    public function testATokenIssuedForAnotherResourceOfTheRealmIsRefused(): void
    {
        $handler = new KeycloakAccessTokenHandler(new FakeKeycloakHttpClient(), new McpGrantsTokenStore(), 'http://keycloak/userinfo', audience: 'sylius-mcp');

        $badge = $handler->getUserBadgeFrom(FakeKeycloakHttpClient::token('jean@example.com', [], audiences: ['account', 'sylius-mcp']));
        self::assertSame(FakeKeycloakHttpClient::subOf('jean@example.com'), $badge->getUserIdentifier());

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('audience "sylius-mcp"');

        $handler->getUserBadgeFrom(FakeKeycloakHttpClient::token('jean@example.com', [], audiences: ['account', 'tartampion-mcp']));
    }

    public function testWithoutAConfiguredAudienceAnyGenuineTokenOfTheRealmIsAccepted(): void
    {
        foreach ([null, ''] as $audience) {
            $handler = new KeycloakAccessTokenHandler(new FakeKeycloakHttpClient(), new McpGrantsTokenStore(), 'http://keycloak/userinfo', audience: $audience);

            $badge = $handler->getUserBadgeFrom(FakeKeycloakHttpClient::token('jean@example.com', [], audiences: ['tartampion-mcp']));

            self::assertSame(FakeKeycloakHttpClient::subOf('jean@example.com'), $badge->getUserIdentifier());
        }
    }

    public function testUserinfoWithoutASubjectIsRefused(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['email' => 'jean@example.com'], \JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]));
        $handler = new KeycloakAccessTokenHandler($client, new McpGrantsTokenStore(), 'http://keycloak/userinfo');

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('"sub"');

        $handler->getUserBadgeFrom(FakeKeycloakHttpClient::token('jean@example.com', []));
    }
}
