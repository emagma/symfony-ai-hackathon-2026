<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Conversation;

/**
 * One model call and what it cost. A turn makes one per tool-loop iteration.
 */
final readonly class LlmCall
{
    /**
     * @param array<string, int> $usage tokens by Langfuse usage type
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $usage,
        public ?float $costUsd,
        public int $durationMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => ($this->usage['input'] ?? 0) + ($this->usage['input_cached_tokens'] ?? 0) + ($this->usage['input_cache_creation'] ?? 0),
            'cached_tokens' => $this->usage['input_cached_tokens'] ?? 0,
            'cache_write_tokens' => $this->usage['input_cache_creation'] ?? 0,
            'output_tokens' => $this->usage['output'] ?? 0,
            'cost_usd' => $this->costUsd,
            'duration_ms' => $this->durationMs,
        ];
    }
}
