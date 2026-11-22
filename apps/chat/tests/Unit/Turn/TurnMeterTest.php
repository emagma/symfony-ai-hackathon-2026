<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Turn;

use Emagma\SyliusAssistantTestChat\Catalog\PriceBook;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderCatalog;
use Emagma\SyliusAssistantTestChat\Conversation\Conversation;
use Emagma\SyliusAssistantTestChat\Cost\CostCalculator;
use Emagma\SyliusAssistantTestChat\Tracing\TurnTracer;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Emagma\SyliusAssistantTestChat\Turn\TurnMeter;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(TurnMeter::class)]
final class TurnMeterTest extends TestCase
{
    /**
     * The counter moves call by call: each model call is priced with
     * Langfuse's prices and reported with the running totals.
     */
    public function testEachCallIsPricedWithLangfusesPricesAndReported(): void
    {
        $events = [];
        $turn = new TurnContext();
        $turn->start('jwt', static function (string $event, array $data) use (&$events): void {
            $events[] = $data;
        });
        $conversation = new Conversation('2f535536-535e-4fbd-a4a3-fefa0f6d8f9f', 'admin', new MessageBag());

        $meter = $this->meter($turn, ['input' => 1e-6, 'input_cached_tokens' => 1e-7, 'input_cache_creation' => 1.25e-6, 'output' => 5e-6]);
        $meter->begin($conversation);
        $meter->record('anthropic', 'claude-haiku-4-5', new TokenUsage(promptTokens: 1_000, completionTokens: 100, cacheCreationTokens: 40_000), microtime(true));
        $meter->record('anthropic', 'claude-haiku-4-5', new TokenUsage(promptTokens: 500, completionTokens: 200, cacheReadTokens: 40_000), microtime(true));

        // 0.001 + 0.05 + 0.0005 = 0.0515 ; 0.0005 + 0.004 + 0.001 = 0.0055
        self::assertEqualsWithDelta(0.0515, $events[0]['cost_usd'], 1e-9);
        self::assertSame(41_000, $events[0]['input_tokens']);
        self::assertSame(40_000, $events[0]['cache_write_tokens']);
        self::assertSame(40_000, $events[1]['cached_tokens']);
        self::assertEqualsWithDelta(0.057, $events[1]['turn_cost_usd'], 1e-9);
        self::assertEqualsWithDelta(0.057, $events[1]['conversation_cost_usd'], 1e-9);
    }

    public function testAModelLangfuseDoesNotPriceIsRecordedUnpriced(): void
    {
        $call = $this->meter(new TurnContext(), null)->record('anthropic', 'claude-x', new TokenUsage(promptTokens: 10, completionTokens: 10), microtime(true));

        self::assertNull($call->costUsd);
    }

    /**
     * @param array<string, float>|null $prices
     */
    private function meter(TurnContext $turn, ?array $prices): TurnMeter
    {
        $models = null === $prices ? [] : [['matchPattern' => '(?i)^(claude-haiku-4-5)$', 'isLangfuseManaged' => true, 'pricingTiers' => [['isDefault' => true, 'prices' => $prices]]]];
        $book = new PriceBook(new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse(['data' => $models, 'meta' => ['totalPages' => 1]])), new ArrayAdapter(), 'http://langfuse-web:3000', 'pk', 'sk');
        $catalog = new ProviderCatalog(['anthropic' => ['label' => 'Anthropic', 'platform' => 'ai.platform.anthropic', 'api_key' => 'sk', 'cache_reporting' => 'separate']]);

        return new TurnMeter($catalog, $book, new CostCalculator(), $turn, new TurnTracer(new NoopTracerProvider()));
    }
}
