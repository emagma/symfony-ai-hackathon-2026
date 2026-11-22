<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Catalog;

final readonly class Provider
{
    /** The cached prompt tokens are part of the prompt tokens (OpenAI, Mistral, Qwen). */
    public const CACHE_INCLUSIVE = 'inclusive';

    /** The prompt tokens exclude cache reads and writes, reported apart (Anthropic). */
    public const CACHE_SEPARATE = 'separate';

    public bool $hasApiKey;

    public function __construct(
        public string $key,
        public string $label,
        public string $platformServiceId,
        #[\SensitiveParameter]
        public string $apiKey,
        public string $cacheReporting,
        public ?string $baseUrl = null,
        public ?string $defaultModel = null,
    ) {
        $this->hasApiKey = '' !== trim($apiKey);
    }
}
