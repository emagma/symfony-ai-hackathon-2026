<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Catalog;

use Emagma\SyliusAssistantTestChat\Catalog\Provider;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderModels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(ProviderModels::class)]
final class ProviderModelsTest extends TestCase
{
    /**
     * The list endpoints mix in embeddings, speech, image and realtime models:
     * only those that can hold a tool-calling conversation are offered.
     */
    public function testOnlyChatModelsAreOffered(): void
    {
        $models = $this->models(['data' => array_map(static fn (string $id): array => ['id' => $id], [
            'gpt-5.4-nano', 'text-embedding-3-small', 'gpt-4o-mini-tts', 'whisper-1', 'gpt-realtime', 'gpt-image-1', 'o4-mini', 'omni-moderation-latest',
        ])]);

        self::assertSame(['gpt-5.4-nano', 'o4-mini'], $models->list(new Provider('openai', 'OpenAI', 'ai.platform.openai', 'sk', Provider::CACHE_INCLUSIVE)));
    }

    /**
     * Mistral says which models can chat and call tools: that is the filter.
     */
    public function testMistralIsFilteredOnItsDeclaredCapabilities(): void
    {
        $models = $this->models(['data' => [
            ['id' => 'mistral-small-latest', 'capabilities' => ['completion_chat' => true, 'function_calling' => true]],
            ['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false, 'function_calling' => false]],
            ['id' => 'codestral-latest', 'capabilities' => ['completion_chat' => true, 'function_calling' => false]],
        ]]);

        self::assertSame(['mistral-small-latest'], $models->list(new Provider('mistral', 'Mistral', 'ai.platform.mistral', 'key', Provider::CACHE_INCLUSIVE)));
    }

    public function testNoKeyNoCall(): void
    {
        $models = new ProviderModels(new MockHttpClient(static fn (): never => throw new \LogicException('no call without a key')), new ArrayAdapter());

        self::assertSame([], $models->list(new Provider('openai', 'OpenAI', 'ai.platform.openai', '', Provider::CACHE_INCLUSIVE)));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function models(array $body): ProviderModels
    {
        return new ProviderModels(new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse($body)), new ArrayAdapter());
    }
}
