<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Conversation;

use Emagma\SyliusAssistantTestChat\Conversation\Conversation;
use Emagma\SyliusAssistantTestChat\Conversation\ServerInstructions;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\McpBundle\Exception\ConnectionException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

#[CoversClass(ServerInstructions::class)]
#[CoversClass(Conversation::class)]
final class ServerInstructionsTest extends TestCase
{
    public function testTheServerInstructionsAreAppendedToTheSystemPromptOnce(): void
    {
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->expects(self::once())->method('getInstructions')->willReturn("Every amount is in minor units.\n");
        $connection->method('getTools')->willReturn([self::tool()]);
        $conversation = $this->conversation();

        $instructions = new ServerInstructions($connection, new NullLogger());
        $instructions->completeSystemPrompt($conversation);
        $instructions->completeSystemPrompt($conversation);

        self::assertCount(1, $conversation->messages);
        self::assertSame(
            "You are the assistant.\n\n" . Conversation::SERVER_INSTRUCTIONS_HEADING . "\n\nEvery amount is in minor units.",
            $conversation->messages->getSystemMessage()?->getContent(),
        );
        self::assertTrue($conversation->hasServerInstructions());
    }

    public function testTheSystemMessageStaysFirstAndTheHistoryIsKept(): void
    {
        $connection = $this->createStub(ServerConnectionInterface::class);
        $connection->method('getInstructions')->willReturn('Conventions.');
        $connection->method('getTools')->willReturn([self::tool()]);
        $conversation = $this->conversation();
        $conversation->messages->add(Message::ofUser('Bonjour'));

        (new ServerInstructions($connection, new NullLogger()))->completeSystemPrompt($conversation);

        $messages = $conversation->messages->getMessages();
        self::assertCount(2, $messages);
        self::assertStringContainsString('Conventions.', (string) $conversation->messages->getSystemMessage()?->getContent());
        self::assertSame('Bonjour', $conversation->messages->getUserMessage()?->asText());
    }

    public function testAnUnreachableServerLeavesThePromptAndTriesAgainNextTurn(): void
    {
        $connection = $this->createStub(ServerConnectionInterface::class);
        $connection->method('getInstructions')->willThrowException(ConnectionException::connectFailed('sylius', 'shop', new \RuntimeException('down')));
        $conversation = $this->conversation();

        (new ServerInstructions($connection, new NullLogger()))->completeSystemPrompt($conversation);

        self::assertSame("You are the assistant.", $conversation->messages->getSystemMessage()?->getContent());
        self::assertFalse($conversation->hasServerInstructions());
    }

    public function testAServerWithNoInstructionsIsNotAskedAgain(): void
    {
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->expects(self::once())->method('getInstructions')->willReturn(null);
        $connection->method('getTools')->willReturn([self::tool()]);
        $conversation = $this->conversation();

        $instructions = new ServerInstructions($connection, new NullLogger());
        $instructions->completeSystemPrompt($conversation);
        $instructions->completeSystemPrompt($conversation);

        self::assertSame("You are the assistant.", $conversation->messages->getSystemMessage()?->getContent());
        self::assertTrue($conversation->hasServerInstructions());
    }

    /**
     * Conversations saved before the flag existed unserialize with it false,
     * so their next turn completes the prompt like a new conversation.
     */
    public function testAConversationSerializedBeforeTheFlagExistedIsCompletedOnItsNextTurn(): void
    {
        $conversation = $this->conversation();
        // An uninitialized property is left out of the payload, exactly as a
        // payload written before the property existed.
        (function (): void {
            unset($this->serverInstructionsApplied);
        })->call($conversation);
        $serialized = serialize($conversation);
        self::assertStringNotContainsString('serverInstructionsApplied', $serialized, 'the fixture must mimic an older payload');

        $older = unserialize($serialized);
        self::assertInstanceOf(Conversation::class, $older);
        self::assertFalse($older->hasServerInstructions());
    }

    public function testAUserServedNoToolIsToldSoInsteadOfTheServersInstructions(): void
    {
        $connection = $this->createStub(ServerConnectionInterface::class);
        $connection->method('getInstructions')->willReturn('Use search_products to list the products.');
        $connection->method('getTools')->willReturn([]);
        $conversation = $this->conversation();

        (new ServerInstructions($connection, new NullLogger()))->completeSystemPrompt($conversation);

        $prompt = (string) $conversation->messages->getSystemMessage()?->getContent();
        self::assertStringContainsString(ServerInstructions::NO_TOOL, $prompt);
        self::assertStringNotContainsString('search_products', $prompt, 'no tool named to a user who has none');
    }

    private static function tool(): Tool
    {
        return new Tool('search_products', null, ['type' => 'object'], null, null);
    }

    private function conversation(): Conversation
    {
        return new Conversation('2f535536-535e-4fbd-a4a3-fefa0f6d8f9f', 'admin', new MessageBag(Message::forSystem("You are the assistant.")));
    }
}
