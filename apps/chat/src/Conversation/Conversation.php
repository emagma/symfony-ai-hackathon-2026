<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Conversation;

use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;

/**
 * A chat conversation: the message history the models see (shared across
 * model switches) and every model call it cost.
 */
final class Conversation
{
    /** What the chat replays when a conversation is reopened, besides the messages. */
    public const ACTIVITY_EVENTS = ['llm_call', 'tool_call', 'elicitation_resolved'];

    private const TITLE_LENGTH = 60;

    /** Separates the chat's own prompt from what the MCP server declared at initialize. */
    public const SERVER_INSTRUCTIONS_HEADING = '## Conventions of the MCP server';

    /** @var list<LlmCall> */
    private array $calls = [];

    /**
     * Shown to the user: the messages, and the activity of each turn (model
     * calls, tool calls, confirmations) so that a reopened conversation looks
     * the way it did.
     *
     * @var list<array{role: string, text?: string, kind?: string, data?: array<string, mixed>}>
     */
    private array $transcript = [];

    // Nullable with defaults: conversations serialized before these existed
    // still unserialize.
    private ?\DateTimeImmutable $createdAt = null;

    private ?\DateTimeImmutable $updatedAt = null;

    /** Whether the MCP server's instructions were appended to the system prompt (see {@see ServerInstructions}). */
    private bool $serverInstructionsApplied = false;

    public function __construct(
        public readonly string $id,
        public readonly string $owner,
        public readonly MessageBag $messages,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function record(LlmCall $call): void
    {
        $this->calls[] = $call;
    }

    public function say(string $role, string $text): void
    {
        $this->transcript[] = ['role' => $role, 'text' => $text];
    }

    /**
     * @param array<string, mixed> $data the event as the UI received it
     */
    public function activity(string $kind, array $data): void
    {
        $this->transcript[] = ['role' => 'activity', 'kind' => $kind, 'data' => $data];
    }

    public function hasServerInstructions(): bool
    {
        return $this->serverInstructionsApplied;
    }

    /**
     * Appends the server's conventions to the system message, once. An empty
     * text still counts as applied: a server that states nothing is not asked
     * again on every turn.
     */
    public function adoptServerInstructions(string $instructions): void
    {
        $this->serverInstructionsApplied = true;
        if ('' === $instructions) {
            return;
        }

        $current = $this->messages->getSystemMessage()?->getContent();
        $prompt = \is_string($current) && '' !== trim($current) ? rtrim($current) . "\n\n" : '';

        $this->messages->removeSystemMessage();
        $this->messages->prepend(new SystemMessage($prompt . self::SERVER_INSTRUCTIONS_HEADING . "\n\n" . $instructions));
    }

    /**
     * Sets the part of the system message that lists the skills the user
     * installed, replacing the one of the previous turn: the list changes
     * between turns. Always the last part of the message; empty removes it.
     */
    public function installSkills(string $heading, string $block): void
    {
        $current = $this->messages->getSystemMessage()?->getContent();
        $prompt = \is_string($current) ? $current : '';
        if (false !== $at = strpos($prompt, $heading)) {
            $prompt = substr($prompt, 0, $at);
        }
        $prompt = rtrim($prompt);
        if ('' !== $block) {
            $prompt .= ('' === $prompt ? '' : "\n\n") . $block;
        }
        if ($prompt === $current) {
            return;
        }

        $this->messages->removeSystemMessage();
        $this->messages->prepend(new SystemMessage($prompt));
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->createdAt ??= $this->updatedAt;
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt ?? $this->createdAt;
    }

    /**
     * The first thing the user asked, shortened.
     */
    public function title(): string
    {
        foreach ($this->transcript as $line) {
            if ('user' === $line['role'] && isset($line['text'])) {
                $title = trim((string) preg_replace('/\s+/u', ' ', $line['text']));

                return mb_strlen($title) > self::TITLE_LENGTH ? rtrim(mb_substr($title, 0, self::TITLE_LENGTH - 1)) . '…' : $title;
            }
        }

        return 'New conversation';
    }

    /**
     * The models used, in order of first use.
     *
     * @return list<string>
     */
    public function models(): array
    {
        return array_values(array_unique(array_map(static fn (LlmCall $call): string => $call->model, $this->calls)));
    }

    /** @return list<LlmCall> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<array{role: string, text?: string, kind?: string, data?: array<string, mixed>}> */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /**
     * Sum of the priced calls; a call with an unknown price adds nothing,
     * see isCostComplete().
     */
    public function costUsd(): float
    {
        return array_sum(array_map(static fn (LlmCall $call): float => $call->costUsd ?? 0.0, $this->calls));
    }

    public function isCostComplete(): bool
    {
        return array_all($this->calls, static fn (LlmCall $call): bool => null !== $call->costUsd);
    }
}
