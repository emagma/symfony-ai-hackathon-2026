<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Conversation;

use Psr\Log\LoggerInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\McpBundle\Exception\ExceptionInterface;

/**
 * Puts the MCP server's `instructions` in front of the model.
 *
 * The server states once, at `initialize`, the conventions every tool shares:
 * how a write gets confirmed, what a missing resource looks like, what the
 * amounts are counted in. Claude.ai injects that text into the system prompt
 * by itself; this chat has to do it, or its models never see it and the tool
 * descriptions - trimmed of exactly that text - stop telling the whole story.
 *
 * It happens on the first turn, not when the conversation is opened: reaching
 * the server takes the user's bearer, which {@see \Emagma\SyliusAssistantTestChat\Turn\TurnContext}
 * only holds inside a turn. Once appended, the text is part of the persisted
 * system message, like the rest of the prompt: appended once, never again.
 */
final class ServerInstructions
{
    public const string NO_TOOL = 'The MCP server serves no tool to the logged-in user: you can neither read nor change the shop.'
        . ' Say so plainly, and never write a tool call in the text of your answer.';

    public function __construct(
        private readonly ServerConnectionInterface $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function completeSystemPrompt(Conversation $conversation): void
    {
        if ($conversation->hasServerInstructions()) {
            return;
        }

        try {
            $instructions = $this->connection->getInstructions();
            $tools = $this->connection->getTools();
        } catch (ExceptionInterface $e) {
            // Not marked: the next turn tries again. The turn itself goes on,
            // the tool loop will surface the same unreachable server anyway.
            $this->logger->warning('The MCP server instructions could not be fetched; the system prompt stays as it is for this turn.', [
                'exception' => $e,
            ]);

            return;
        }

        // The server serves this user no tool: its instructions, which name tools, would make the model write
        // fake tool calls. It is told instead that it can do nothing in the shop.
        $conversation->adoptServerInstructions([] === $tools ? self::NO_TOOL : trim((string) $instructions));
    }
}
