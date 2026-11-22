<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Catalog;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Declares into Langfuse's model table the prices Langfuse does not maintain
 * itself (Mistral, Qwen), from config/langfuse-models.yaml. PriceBook and the
 * traces then read the same price.
 *
 * Idempotent by model name: a project-defined model already in Langfuse is left
 * as is (to change a price, delete the model in Langfuse first). Run by
 * `bin/console testchat:prices` (`castor chat:prices`).
 */
final class LangfusePriceDeclarer
{
    /** Langfuse lets a model id carry a provider prefix: match it with or without. */
    private const PREFIX = '(?i)^(mistral\/|qwen\/|dashscope\/)?';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(TESTCHAT_LANGFUSE_URL)%')]
        private readonly string $langfuseUrl,
        #[Autowire('%env(LANGFUSE_PROJECT_PUBLIC_KEY)%')]
        private readonly string $publicKey,
        #[Autowire('%env(LANGFUSE_PROJECT_SECRET_KEY)%')]
        private readonly string $secretKey,
        #[Autowire('%kernel.project_dir%/config/langfuse-models.yaml')]
        private readonly string $modelsFile,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->langfuseUrl && '' !== $this->publicKey && '' !== $this->secretKey;
    }

    /**
     * @return array{created: list<string>, existing: list<string>}
     */
    public function declare(): array
    {
        $existing = $this->projectModels();
        $created = [];
        $kept = [];

        foreach ($this->models() as $name => $model) {
            if (\in_array($name, $existing, true)) {
                $kept[] = $name;

                continue;
            }

            $this->httpClient->request('POST', $this->url('/api/public/models'), $this->options() + ['json' => [
                'modelName' => $name,
                'matchPattern' => self::PREFIX . $model['match'] . '$',
                'unit' => 'TOKENS',
                'pricingTiers' => [[
                    'name' => 'Standard',
                    'isDefault' => true,
                    'priority' => 0,
                    'conditions' => [],
                    'prices' => array_map(static fn (float $perMillion): float => $perMillion / 1_000_000, $model['prices']),
                ]],
            ]])->toArray();
            $created[] = $name;
        }

        return ['created' => $created, 'existing' => $kept];
    }

    /**
     * Names of the models the project already declared (not Langfuse-managed).
     *
     * @return list<string>
     */
    private function projectModels(): array
    {
        $names = [];
        for ($page = 1; $page <= 20; ++$page) {
            $body = $this->httpClient->request('GET', $this->url('/api/public/models'), $this->options() + [
                'query' => ['page' => $page, 'limit' => 100],
            ])->toArray();

            $data = \is_array($body['data'] ?? null) ? $body['data'] : [];
            foreach ($data as $model) {
                if (\is_array($model) && false === ($model['isLangfuseManaged'] ?? true) && \is_string($model['modelName'] ?? null)) {
                    $names[] = $model['modelName'];
                }
            }

            $meta = \is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $total = \is_int($meta['totalPages'] ?? null) ? $meta['totalPages'] : 1;
            if ([] === $data || $page >= $total) {
                break;
            }
        }

        return $names;
    }

    /**
     * @return array<string, array{match: string, prices: array<string, float>}>
     */
    private function models(): array
    {
        $config = Yaml::parseFile($this->modelsFile);
        $models = \is_array($config) && \is_array($config['models'] ?? null) ? $config['models'] : [];

        $typed = [];
        foreach ($models as $name => $model) {
            if (!\is_string($name) || !\is_array($model) || !\is_string($model['match'] ?? null) || !\is_array($model['prices'] ?? null)) {
                throw new \UnexpectedValueException(\sprintf('%s: model "%s" needs a `match` string and a `prices` map.', $this->modelsFile, (string) $name));
            }

            $prices = [];
            foreach ($model['prices'] as $type => $price) {
                if (!\is_string($type) || (!\is_int($price) && !\is_float($price))) {
                    throw new \UnexpectedValueException(\sprintf('%s: model "%s" has a non-numeric price.', $this->modelsFile, $name));
                }
                $prices[$type] = (float) $price;
            }
            $typed[$name] = ['match' => $model['match'], 'prices' => $prices];
        }

        return $typed;
    }

    private function url(string $path): string
    {
        return rtrim($this->langfuseUrl, '/') . $path;
    }

    /**
     * @return array{auth_basic: array{string, string}, timeout: int}
     */
    private function options(): array
    {
        return ['auth_basic' => [$this->publicKey, $this->secretKey], 'timeout' => 10];
    }
}
