<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Catalog;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Model prices, read from Langfuse's own price table (/api/public/models): the
 * one Langfuse prices the traces with, maintained by Langfuse for OpenAI and
 * Anthropic, extended by the project for the rest (testchat:prices, or
 * Settings → Models in its UI). The chat and Langfuse can then never disagree.
 *
 * A model is matched the way Langfuse matches it: its `matchPattern` regex, a
 * project-defined model before a Langfuse-managed one, the latest start date
 * first. Only the default pricing tier is used.
 */
final class PriceBook
{
    private const TTL = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire('%env(TESTCHAT_LANGFUSE_URL)%')]
        private readonly string $langfuseUrl,
        #[Autowire('%env(LANGFUSE_PROJECT_PUBLIC_KEY)%')]
        private readonly string $publicKey,
        #[Autowire('%env(LANGFUSE_PROJECT_SECRET_KEY)%')]
        private readonly string $secretKey,
    ) {
    }

    /**
     * Whether the chat has a price source: a Langfuse to read the prices from.
     * Without one, the chat shows tokens only and mentions no price.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->langfuseUrl && '' !== $this->publicKey;
    }

    /**
     * Price per token of each usage type (input, input_cached_tokens,
     * input_cache_creation, output…), or null when Langfuse has no price for
     * this model.
     *
     * @return array<string, float>|null
     */
    public function prices(string $model): ?array
    {
        foreach ($this->definitions() as $definition) {
            if (1 === @preg_match($definition['pattern'], $model)) {
                return $definition['prices'];
            }
        }

        return null;
    }

    /**
     * @return list<array{pattern: string, prices: array<string, float>}>
     */
    private function definitions(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            return $this->cache->get('testchat.langfuse_prices', function (ItemInterface $item): array {
                $item->expiresAfter(self::TTL);

                return self::compile($this->fetch());
            });
        } catch (\Throwable) {
            // Langfuse down: prices unknown, the chat still works.
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(): array
    {
        $models = [];
        for ($page = 1; $page <= 20; ++$page) {
            $body = $this->httpClient->request('GET', rtrim($this->langfuseUrl, '/') . '/api/public/models', [
                'query' => ['page' => $page, 'limit' => 100],
                'auth_basic' => [$this->publicKey, $this->secretKey],
                'timeout' => 10,
            ])->toArray();

            $data = \is_array($body['data'] ?? null) ? $body['data'] : [];
            foreach ($data as $model) {
                if (\is_array($model)) {
                    /** @var array<string, mixed> $typed */
                    $typed = $model;
                    $models[] = $typed;
                }
            }

            $meta = \is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $total = \is_int($meta['totalPages'] ?? null) ? $meta['totalPages'] : 1;
            if ([] === $data || $page >= $total) {
                break;
            }
        }

        return $models;
    }

    /**
     * @param list<array<string, mixed>> $models
     *
     * @return list<array{pattern: string, prices: array<string, float>}>
     */
    public static function compile(array $models): array
    {
        $rank = static fn (array $model): array => [
            false === ($model['isLangfuseManaged'] ?? true) ? 0 : 1,
            -(int) strtotime(\is_string($model['startDate'] ?? null) ? $model['startDate'] : '1970-01-01'),
        ];
        usort($models, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        $definitions = [];
        foreach ($models as $model) {
            $pattern = self::regex(\is_string($model['matchPattern'] ?? null) ? $model['matchPattern'] : '');
            $prices = self::defaultTier($model);
            if (null !== $pattern && [] !== $prices) {
                $definitions[] = ['pattern' => $pattern, 'prices' => $prices];
            }
        }

        return $definitions;
    }

    /**
     * Langfuse stores PostgreSQL regexes, `(?i)` prefix for case-insensitive.
     */
    private static function regex(string $matchPattern): ?string
    {
        if ('' === $matchPattern) {
            return null;
        }

        $flags = '';
        if (str_starts_with($matchPattern, '(?i)')) {
            $matchPattern = substr($matchPattern, 4);
            $flags = 'i';
        }
        $regex = '~' . str_replace('~', '\~', $matchPattern) . '~' . $flags;

        return false === @preg_match($regex, '') ? null : $regex;
    }

    /**
     * @param array<string, mixed> $model
     *
     * @return array<string, float>
     */
    private static function defaultTier(array $model): array
    {
        $prices = [];
        foreach (\is_array($model['pricingTiers'] ?? null) ? $model['pricingTiers'] : [] as $tier) {
            if (\is_array($tier) && true === ($tier['isDefault'] ?? false) && \is_array($tier['prices'] ?? null)) {
                $prices = $tier['prices'];
            }
        }
        if ([] === $prices && \is_array($model['prices'] ?? null)) {
            $prices = array_map(static fn (mixed $price): mixed => \is_array($price) ? ($price['price'] ?? null) : null, $model['prices']);
        }

        $perToken = [];
        foreach ($prices as $type => $price) {
            if (\is_string($type) && is_numeric($price)) {
                $perToken[$type] = (float) $price;
            }
        }

        return $perToken;
    }
}
