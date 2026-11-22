<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Turn;

use Emagma\SyliusAssistantTestChat\Conversation\Conversation;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\MessageBag;

#[CoversClass(TurnContext::class)]
final class TurnContextTest extends TestCase
{
    /**
     * A reopened conversation replays its finished steps: model calls, tool
     * calls once ended, confirmations - not the transient events.
     */
    public function testTheFinishedStepsOfATurnAreRecordedForReplay(): void
    {
        $conversation = new Conversation('2f535536-535e-4fbd-a4a3-fefa0f6d8f9f', 'admin', new MessageBag());
        $turn = new TurnContext();
        $turn->start('jwt');
        $turn->recordInto($conversation);

        $turn->emit('turn_started', ['provider' => 'openai']);
        $turn->emit('llm_call', ['model' => 'gpt-5.4-mini']);
        $turn->emit('tool_call', ['name' => 'shop_list_products', 'status' => 'started']);
        $turn->emit('tool_call', ['name' => 'shop_list_products', 'status' => 'done']);
        $turn->emit('elicitation_resolved', ['outcome' => 'manual_decline']);
        $turn->emit('done', []);

        self::assertSame(['llm_call', 'tool_call', 'elicitation_resolved'], array_column($conversation->transcript(), 'kind'));
        self::assertSame('done', $conversation->transcript()[1]['data']['status'] ?? null);
    }
}
