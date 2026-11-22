<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Turn;

use Emagma\SyliusAssistantTestChat\Conversation\Conversation;
use Emagma\SyliusAssistantTestChat\Conversation\ConversationStore;
use Emagma\SyliusAssistantTestChat\Conversation\ServerInstructions;
use Emagma\SyliusAssistantTestChat\Skill\SkillCatalog;
use Emagma\SyliusAssistantTestChat\Tracing\TurnTracer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;

/**
 * Runs one chat turn: the user's message, the model's tool loop through
 * /_mcp, the answer - reporting every step through the TurnContext emitter.
 */
final class TurnRunner
{
    public function __construct(
        private readonly AgentFactory $agents,
        private readonly ConversationStore $store,
        private readonly TurnMeter $meter,
        private readonly TurnContext $turn,
        private readonly TurnTracer $tracer,
        private readonly ServerInstructions $instructions,
        private readonly ?SkillCatalog $skills = null,
    ) {
    }

    public function run(Conversation $conversation, string $message, string $provider, string $model): void
    {
        $this->meter->begin($conversation);
        $this->turn->recordInto($conversation);
        // Needs the bearer the turn just received, hence here and not at creation.
        $this->instructions->completeSystemPrompt($conversation);
        // After them: the skills the user installed, as of this turn.
        if (null !== $this->skills) {
            $conversation->installSkills(SkillCatalog::HEADING, $this->skills->prompt($conversation->owner));
        }
        $this->tracer->beginTurn($conversation->id, $conversation->owner, $provider, $model, $message);
        $this->turn->setTraceparent($this->tracer->turnTraceparent());
        $answer = $error = null;
        $this->turn->emit('turn_started', ['conversation_id' => $conversation->id, 'provider' => $provider, 'model' => $model]);

        $conversation->messages->add(Message::ofUser($message));
        $conversation->say('user', $message);

        try {
            $answer = self::answer($this->agents->create($provider, $model)->call($conversation->messages, AgentFactory::callOptions($provider))->getResult());
            $conversation->messages->add(Message::ofAssistant($answer));
            $conversation->say('assistant', $answer);
            $this->turn->emit('assistant', ['text' => $answer]);
        } catch (\Throwable $e) {
            // The calls already made were paid for: keep them, and the history
            // up to the failure, so the next turn can go on.
            // An assistant message keeps the user/assistant alternation that
            // some providers require on the next turn.
            $conversation->messages->add(Message::ofAssistant('(The turn failed: ' . $e->getMessage() . ')'));
            $conversation->say('error', $e->getMessage());
            $this->turn->emit('error', ['message' => $e->getMessage()]);
            $error = $e->getMessage();
        } finally {
            $this->tracer->endTurn($answer, $error, $this->meter->turnCost());
            $this->store->save($conversation);
            $this->turn->emit('done', [
                'turn_cost_usd' => $this->meter->turnCost(),
                'conversation_cost_usd' => $conversation->costUsd(),
                'cost_complete' => $conversation->isCostComplete(),
                'calls' => \count($conversation->calls()),
            ]);
        }
    }

    /**
     * The text of the model's final answer. A model may return it in several
     * parts - a reasoning summary next to the text, or the text split in
     * pieces (OpenAI Responses API): only the text parts are kept, joined.
     * json_encode on those part objects gave `[{},{}]`.
     */
    public static function answer(ResultInterface $result): string
    {
        if ($result instanceof MultiPartResult) {
            return $result->asText();
        }

        $content = $result->getContent();

        return \is_string($content) ? $content : (string) json_encode($content, \JSON_UNESCAPED_UNICODE);
    }
}
