<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Catalog;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The chat models a provider's API lists right now - every one of them, not a
 * hand-picked few.
 *
 * Each list endpoint also returns embeddings, speech, image and realtime
 * models, which cannot hold a tool-calling conversation. Mistral says which
 * models can (`capabilities`); OpenAI and DashScope do not, so those are
 * filtered by name. Anthropic only lists chat models.
 */
final class ProviderModels
{
    private const TTL = 3600;

    /** Not chat models, by name: OpenAI. */
    private const OPENAI_EXCLUDED = '/embedding|tts|whisper|transcri|audio|realtime|image|sora|moderation|search|babbage|davinci|instruct|dall-e|live|translate|computer-use/i';

    /** Not chat models, by name: DashScope (Qwen and the third-party models it hosts). */
    private const DASHSCOPE_EXCLUDED = '/image|tts|asr|audio|translate|realtime|omni|vl|ocr|qwen-mt|s2s|captioner|character|embed|qvq|-vc|-vd/i';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return list<string> model ids, sorted
     */
    public function list(Provider $provider): array
    {
        if (!$provider->hasApiKey) {
            return [];
        }

        /** @var list<string> $models */
        $models = $this->cache->get('testchat.models.' . $provider->key . '.' . hash('xxh3', $provider->apiKey), function (ItemInterface $item) use ($provider): array {
            $item->expiresAfter(self::TTL);

            $models = match ($provider->key) {
                'openai' => $this->ids($this->get('https://api.openai.com/v1/models', ['Authorization' => 'Bearer ' . $provider->apiKey]), self::OPENAI_EXCLUDED),
                'anthropic' => $this->ids($this->get('https://api.anthropic.com/v1/models?limit=1000', ['x-api-key' => $provider->apiKey, 'anthropic-version' => '2023-06-01'])),
                'mistral' => $this->mistral($this->get('https://api.mistral.ai/v1/models', ['Authorization' => 'Bearer ' . $provider->apiKey])),
                'qwen' => $this->ids($this->get(rtrim($provider->baseUrl ?? '', '/') . '/v1/models', ['Authorization' => 'Bearer ' . $provider->apiKey]), self::DASHSCOPE_EXCLUDED),
                default => [],
            };
            sort($models);

            return $models;
        });

        return $models;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<array<string, mixed>>
     */
    private function get(string $url, array $headers): array
    {
        $data = $this->httpClient->request('GET', $url, ['headers' => $headers, 'timeout' => 15])->toArray()['data'] ?? [];

        $models = [];
        foreach (\is_array($data) ? $data : [] as $model) {
            if (\is_array($model)) {
                /** @var array<string, mixed> $typed */
                $typed = $model;
                $models[] = $typed;
            }
        }

        return $models;
    }

    /**
     * @param list<array<string, mixed>> $models
     *
     * @return list<string>
     */
    private function ids(array $models, ?string $excluded = null): array
    {
        $ids = array_filter(array_column($models, 'id'), \is_string(...));

        return array_values(null === $excluded ? $ids : array_filter($ids, static fn (string $id): bool => 1 !== preg_match($excluded, $id)));
    }

    /**
     * @param list<array<string, mixed>> $models
     *
     * @return list<string>
     */
    private function mistral(array $models): array
    {
        return $this->ids(array_values(array_filter($models, static function (array $model): bool {
            $capabilities = \is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];

            return true === ($capabilities['completion_chat'] ?? false) && true === ($capabilities['function_calling'] ?? false);
        })));
    }
}
