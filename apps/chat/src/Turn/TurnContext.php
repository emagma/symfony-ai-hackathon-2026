<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Turn;

use Emagma\SyliusAssistantTestChat\Conversation\Conversation;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What the code deep inside a turn needs to know about it: whose token to send
 * to /_mcp, which span to name as the parent, where to report events, and how
 * write confirmations are answered.
 *
 * Set by whoever starts the turn (the chat controller, or the diagnostic
 * command); reset between requests.
 */
final class TurnContext implements ResetInterface
{
    public const ELICITATION_TIMEOUT = 30.0;

    private ?string $bearer = null;

    private ?string $traceparent = null;

    /** @var (\Closure(string, array<string, mixed>): void)|null */
    private ?\Closure $emitter = null;

    private ?string $presetAnswer = null;

    private ?Conversation $conversation = null;

    public function start(string $bearer, ?\Closure $emitter = null, ?string $presetAnswer = null): void
    {
        $this->bearer = $bearer;
        $this->emitter = $emitter;
        $this->presetAnswer = $presetAnswer;
        $this->traceparent = null;
    }

    /**
     * The conversation the turn's activity is recorded into, so that it can be
     * replayed when the conversation is reopened.
     */
    public function recordInto(?Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function bearer(): ?string
    {
        return $this->bearer;
    }

    public function setTraceparent(?string $traceparent): void
    {
        $this->traceparent = $traceparent;
    }

    public function traceparent(): ?string
    {
        return $this->traceparent;
    }

    /**
     * An answer decided in advance (accept / decline), for the command line
     * where nobody is there to click. null = ask the UI.
     */
    public function presetAnswer(): ?string
    {
        return $this->presetAnswer;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function emit(string $event, array $data): void
    {
        // The finished steps only: a tool call is recorded when it ends.
        if (null !== $this->conversation && \in_array($event, Conversation::ACTIVITY_EVENTS, true) && 'started' !== ($data['status'] ?? null)) {
            $this->conversation->activity($event, $data);
        }

        if (null !== $this->emitter) {
            ($this->emitter)($event, $data);
        }
    }

    public function reset(): void
    {
        $this->bearer = $this->traceparent = $this->presetAnswer = null;
        $this->emitter = null;
        $this->conversation = null;
    }
}
