<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Turn;

use Emagma\SyliusAssistantTestChat\Turn\AgentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformOptions;

#[CoversClass(AgentFactory::class)]
final class AgentFactoryTest extends TestCase
{
    public function testAnAnthropicCallIsGivenAnOutputBudgetAboveTheBridgesThousandTokens(): void
    {
        self::assertSame(
            [PlatformOptions::MAX_OUTPUT_TOKENS => AgentFactory::ANTHROPIC_MAX_OUTPUT_TOKENS],
            AgentFactory::callOptions('anthropic'),
        );
        self::assertGreaterThan(1000, AgentFactory::ANTHROPIC_MAX_OUTPUT_TOKENS);
    }

    public function testTheOtherProvidersKeepTheirOwnLimit(): void
    {
        foreach (['openai', 'mistral', 'qwen'] as $provider) {
            self::assertSame([], AgentFactory::callOptions($provider));
        }
    }
}
