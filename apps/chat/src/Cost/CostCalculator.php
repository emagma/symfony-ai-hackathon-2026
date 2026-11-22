<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Cost;

use Emagma\SyliusAssistantTestChat\Catalog\Provider;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Prices a model call exactly as Langfuse does, so the chat's counter and the
 * trace always show the same figure: the usage, split by type, times the price
 * of each type, summed. A type Langfuse has no price for costs nothing there,
 * and nothing here.
 */
final class CostCalculator
{
    /**
     * The usage in Langfuse's terms. `input` is the UNCACHED prompt: OpenAI,
     * Mistral and Qwen count cached tokens inside the prompt, Anthropic apart.
     * `output` already includes reasoning tokens with every provider here.
     *
     * @return array<string, int>
     */
    public function usage(string $cacheReporting, TokenUsageInterface $usage): array
    {
        $prompt = $usage->getPromptTokens() ?? 0;

        if (Provider::CACHE_SEPARATE === $cacheReporting) {
            $cached = $usage->getCacheReadTokens() ?? 0;
            $written = $usage->getCacheCreationTokens() ?? 0;
            $input = $prompt;
        } else {
            $cached = min($usage->getCachedTokens() ?? 0, $prompt);
            $written = 0;
            $input = $prompt - $cached;
        }

        return array_filter([
            'input' => $input,
            'input_cached_tokens' => $cached,
            'input_cache_creation' => $written,
            'output' => $usage->getCompletionTokens() ?? 0,
        ], static fn (int $tokens): bool => $tokens > 0);
    }

    /**
     * @param array<string, int>        $usage  by usage type
     * @param array<string, float>|null $prices per token, by usage type; null = unknown model
     *
     * @return float|null USD, or null when the model has no price
     */
    public function cost(array $usage, ?array $prices): ?float
    {
        if (null === $prices) {
            return null;
        }

        $cost = 0.0;
        foreach ($usage as $type => $tokens) {
            $cost += $tokens * ($prices[$type] ?? 0.0);
        }

        return $cost;
    }
}
