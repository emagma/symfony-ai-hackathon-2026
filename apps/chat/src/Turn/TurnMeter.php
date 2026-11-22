<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Turn;

use Emagma\SyliusAssistantTestChat\Catalog\PriceBook;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderCatalog;
use Emagma\SyliusAssistantTestChat\Conversation\Conversation;
use Emagma\SyliusAssistantTestChat\Conversation\LlmCall;
use Emagma\SyliusAssistantTestChat\Cost\CostCalculator;
use Emagma\SyliusAssistantTestChat\Tracing\TurnTracer;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Prices each model call as it completes - with Langfuse's prices - and
 * reports it to the UI, so the cost counter moves call by call, tool loop
 * included.
 */
final class TurnMeter implements ResetInterface
{
    private ?Conversation $conversation = null;

    private float $turnCost = 0.0;

    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly PriceBook $prices,
        private readonly CostCalculator $calculator,
        private readonly TurnContext $turn,
        private readonly TurnTracer $tracer,
    ) {
    }

    public function begin(Conversation $conversation): void
    {
        $this->conversation = $conversation;
        $this->turnCost = 0.0;
    }

    public function record(string $provider, string $model, ?TokenUsageInterface $usage, float $startedAt): LlmCall
    {
        $usageDetails = null === $usage ? [] : $this->calculator->usage($this->catalog->get($provider)->cacheReporting, $usage);

        $call = new LlmCall(
            $provider,
            $model,
            $usageDetails,
            null === $usage ? null : $this->calculator->cost($usageDetails, $this->prices->prices($model)),
            (int) ((microtime(true) - $startedAt) * 1000),
        );

        // Usage only: Langfuse prices the generation from the same table.
        $this->tracer->generation($provider, $model, $startedAt, $usageDetails + ['total' => array_sum($usageDetails)]);
        $this->turnCost += $call->costUsd ?? 0.0;
        $this->conversation?->record($call);

        $this->turn->emit('llm_call', $call->toArray() + [
            'turn_cost_usd' => $this->turnCost,
            'conversation_cost_usd' => $this->conversation?->costUsd(),
        ]);

        return $call;
    }

    public function turnCost(): float
    {
        return $this->turnCost;
    }

    public function reset(): void
    {
        $this->conversation = null;
        $this->turnCost = 0.0;
    }
}
