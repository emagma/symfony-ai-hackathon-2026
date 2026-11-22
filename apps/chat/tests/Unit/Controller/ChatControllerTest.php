<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Controller;

use Emagma\SyliusAssistantTestChat\Auth\RequireLoginListener;
use Emagma\SyliusAssistantTestChat\Auth\UserToken;
use Emagma\SyliusAssistantTestChat\Catalog\PriceBook;
use Emagma\SyliusAssistantTestChat\Controller\ChatController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversClass(ChatController::class)]
final class ChatControllerTest extends TestCase
{
    public function testThePageWarnsItIsATestChat(): void
    {
        self::assertStringContainsString('Test chat - do not use in production', $this->page(''));
    }

    /**
     * Without a price source the page mentions no price: no cost card, no
     * model price, no "unknown price" - tokens only (app.js reads data-pricing).
     */
    public function testWithoutAPriceSourceThePageMentionsNoPrice(): void
    {
        $page = $this->page('');

        self::assertStringContainsString('data-pricing="0"', $page);
        self::assertStringContainsString('id="conversation-tokens"', $page);
        self::assertStringContainsString('id="call-count"', $page);
        self::assertDoesNotMatchRegularExpression('/price|cost|\$\d/i', $page);
    }

    public function testWithAPriceSourceThePageShowsTheCost(): void
    {
        $page = $this->page('http://langfuse-web:3000');

        self::assertStringContainsString('data-pricing="1"', $page);
        self::assertStringContainsString('id="conversation-cost"', $page);
        self::assertStringContainsString('Model price', $page);
        self::assertStringContainsString('id="conversation-tokens"', $page);
    }

    private function page(string $langfuseUrl): string
    {
        $prices = new PriceBook(new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse(['data' => []])), new ArrayAdapter(), $langfuseUrl, 'pk', 'sk');
        $controller = new ChatController(new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates')), $prices);

        $request = new Request();
        $request->attributes->set(RequireLoginListener::TOKEN_ATTRIBUTE, new UserToken('jwt', null, time() + 300, 'admin', null));

        return (string) $controller($request)->getContent();
    }
}
