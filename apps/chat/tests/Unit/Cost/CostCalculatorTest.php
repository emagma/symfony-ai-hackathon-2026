<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Cost;

use Emagma\SyliusAssistantTestChat\Catalog\Provider;
use Emagma\SyliusAssistantTestChat\Cost\CostCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

#[CoversClass(CostCalculator::class)]
final class CostCalculatorTest extends TestCase
{
    /**
     * OpenAI, Mistral, Qwen count cached tokens INSIDE the prompt: Langfuse's
     * `input` is what remains once they are taken out.
     */
    public function testInclusiveCachingSplitsTheCachedPartOutOfTheInput(): void
    {
        $usage = (new CostCalculator())->usage(Provider::CACHE_INCLUSIVE, new TokenUsage(promptTokens: 100_000, completionTokens: 2_000, cachedTokens: 80_000));

        self::assertSame(['input' => 20_000, 'input_cached_tokens' => 80_000, 'output' => 2_000], $usage);
    }

    /**
     * Anthropic reports cache reads and writes APART from input_tokens.
     */
    public function testSeparateCachingKeepsTheInputAndAddsReadsAndWrites(): void
    {
        $usage = (new CostCalculator())->usage(Provider::CACHE_SEPARATE, new TokenUsage(promptTokens: 1_000, completionTokens: 2_000, cacheCreationTokens: 10_000, cacheReadTokens: 90_000));

        self::assertSame(['input' => 1_000, 'input_cached_tokens' => 90_000, 'input_cache_creation' => 10_000, 'output' => 2_000], $usage);
    }

    /**
     * Langfuse's arithmetic: each usage type times its price, summed; a type
     * without a price costs nothing - here as in the trace.
     */
    public function testTheCostIsTheUsageTimesLangfusesPricePerType(): void
    {
        $cost = (new CostCalculator())->cost(
            ['input' => 1_000, 'input_cached_tokens' => 90_000, 'input_cache_creation' => 10_000, 'output' => 2_000],
            ['input' => 3e-6, 'input_cached_tokens' => 3e-7, 'output' => 1.5e-5],
        );

        // 0.003 + 0.027 + 0 (no price for cache writes) + 0.03
        self::assertEqualsWithDelta(0.06, $cost, 1e-12);
    }

    public function testAModelWithoutPriceHasAnUnknownCostNotZero(): void
    {
        self::assertNull((new CostCalculator())->cost(['input' => 10, 'output' => 10], null));
    }
}
