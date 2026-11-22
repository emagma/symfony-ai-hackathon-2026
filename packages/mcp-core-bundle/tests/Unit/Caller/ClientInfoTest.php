<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Caller;

use Emagma\McpCoreBundle\Caller\ClientInfo;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\RequestMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientInfo::class)]
final class ClientInfoTest extends TestCase
{
    public function testTheHandshakeEraReadsWhatInitializeStored(): void
    {
        $client = ClientInfo::of($this->session(['client_info' => ['name' => 'claude-code', 'version' => '2.1.0']]));

        self::assertSame('claude-code', $client->name);
        self::assertSame('2.1.0', $client->version);
    }

    public function testTheModernEraReadsTheMetaOfTheRequest(): void
    {
        $client = ClientInfo::of($this->session([
            Protocol::SESSION_ACTIVE_REQUEST_META => [RequestMeta::CLIENT_INFO => ['name' => 'openai-mcp', 'version' => '1.0.0']],
        ]));

        self::assertSame('openai-mcp', $client->name);
        self::assertSame('1.0.0', $client->version);
    }

    public function testWhatTheHandshakeStoredWinsOverTheMetaOfARequest(): void
    {
        $client = ClientInfo::of($this->session([
            'client_info' => ['name' => 'some-client', 'version' => '1'],
            Protocol::SESSION_ACTIVE_REQUEST_META => [RequestMeta::CLIENT_INFO => ['name' => 'Anthropic/ClaudeAI']],
        ]));

        self::assertSame('some-client', $client->name);
    }

    public function testAClientThatDeclaredNothingHasNoName(): void
    {
        foreach ([[], ['client_info' => 'x'], ['client_info' => ['name' => '']], [Protocol::SESSION_ACTIVE_REQUEST_META => ['progressToken' => 't']]] as $data) {
            $client = ClientInfo::of($this->session($data));

            self::assertNull($client->name);
            self::assertNull($client->version);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function session(array $data): SessionInterface
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $data[$key] ?? $default);

        return $session;
    }
}
