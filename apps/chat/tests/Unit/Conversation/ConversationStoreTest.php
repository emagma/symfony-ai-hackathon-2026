<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Conversation;

use Emagma\SyliusAssistantTestChat\Conversation\ConversationStore;
use Emagma\SyliusAssistantTestChat\Conversation\LlmCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ConversationStore::class)]
final class ConversationStoreTest extends TestCase
{
    private const ID = '2f535536-535e-4fbd-a4a3-fefa0f6d8f9f';

    private string $directory;

    private string $prompt;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/testchat-' . bin2hex(random_bytes(4));
        $this->prompt = $this->directory . '/system.md';
        (new Filesystem())->dumpFile($this->prompt, 'You are the assistant.');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAConversationRoundTripsWithItsHistoryAndItsCost(): void
    {
        $store = new ConversationStore($this->directory, $this->prompt);
        $conversation = $store->load(self::ID, 'admin');
        $conversation->messages->add(Message::ofUser('Liste 2 produits'));
        $conversation->record(new LlmCall('qwen', 'qwen-plus', ['input' => 1000, 'output' => 10], 0.0004, 50));
        $store->save($conversation);

        $reloaded = $store->load(self::ID, 'admin');

        self::assertCount(2, $reloaded->messages);
        self::assertEqualsWithDelta(0.0004, $reloaded->costUsd(), 1e-12);
    }

    /**
     * Set once at creation: re-adding it every turn would grow the history.
     */
    public function testANewConversationStartsWithTheSystemPromptOnly(): void
    {
        $messages = (new ConversationStore($this->directory, $this->prompt))->load(self::ID, 'admin')->messages;

        self::assertCount(1, $messages);
        self::assertSame('You are the assistant.', $messages->getSystemMessage()?->getContent());
    }

    public function testAnotherUsersConversationIsNotLoaded(): void
    {
        $store = new ConversationStore($this->directory, $this->prompt);
        $store->save($store->load(self::ID, 'admin'));

        $this->expectException(\DomainException::class);
        $store->load(self::ID, 'catalog-reader');
    }

    public function testAnIdIsAUuidNotAPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConversationStore($this->directory, $this->prompt))->load('../../config/secrets', 'admin');
    }

    public function testTheListShowsOnlyTheUsersConversationsMostRecentFirst(): void
    {
        $store = new ConversationStore($this->directory, $this->prompt);
        foreach (['11111111-1111-4111-8111-111111111111' => 'admin', '22222222-2222-4222-8222-222222222222' => 'catalog-manager', '33333333-3333-4333-8333-333333333333' => 'admin'] as $id => $owner) {
            $conversation = $store->load($id, $owner);
            $conversation->say('user', 'Question de ' . $owner);
            $store->save($conversation);
            usleep(1_100_000);
        }

        $list = $store->list('admin');

        self::assertSame(['33333333-3333-4333-8333-333333333333', '11111111-1111-4111-8111-111111111111'], array_column($list, 'id'));
        self::assertSame('Question de admin', $list[0]['title']);
    }

    /**
     * Conversations saved before the summaries existed still show up.
     */
    public function testAConversationWithoutSummaryGetsOne(): void
    {
        $store = new ConversationStore($this->directory, $this->prompt);
        $conversation = $store->load(self::ID, 'admin');
        $conversation->say('user', 'Ancienne conversation');
        $store->save($conversation);
        (new Filesystem())->remove($this->directory . '/' . self::ID . '.json');

        self::assertSame(['Ancienne conversation'], array_column($store->list('admin'), 'title'));
        self::assertFileExists($this->directory . '/' . self::ID . '.json');
    }

    /**
     * A conversation saved by an older TestChat, whose classes have changed
     * since, is skipped by the list and refused at opening - never a 500.
     */
    public function testAnUnreadableOldConversationIsSkipped(): void
    {
        (new Filesystem())->dumpFile($this->directory . '/' . self::ID . '.ser', serialize(new \stdClass()));
        $store = new ConversationStore($this->directory, $this->prompt);

        self::assertSame([], $store->list('admin'));
        $this->expectException(\DomainException::class);
        $store->load(self::ID, 'admin');
    }

    public function testAnotherUsersConversationCannotBeDeleted(): void
    {
        $store = new ConversationStore($this->directory, $this->prompt);
        $store->save($store->load(self::ID, 'admin'));

        try {
            $store->delete(self::ID, 'catalog-reader');
            self::fail('Deleting another user\'s conversation must be refused.');
        } catch (\DomainException) {
        }

        self::assertTrue($store->delete(self::ID, 'admin'));
        self::assertSame([], $store->list('admin'));
        self::assertFileDoesNotExist($this->directory . '/' . self::ID . '.ser');
    }

    public function testTheTitleIsTheFirstQuestionShortened(): void
    {
        $conversation = (new ConversationStore($this->directory, $this->prompt))->load(self::ID, 'admin');
        self::assertSame('New conversation', $conversation->title());

        $conversation->say('user', str_repeat('List the products of the shop ', 10));
        $conversation->say('user', 'Second question');

        self::assertSame(60, mb_strlen($conversation->title()));
        self::assertStringEndsWith('…', $conversation->title());
    }

    public function testAnUnpricedCallMakesTheCostIncomplete(): void
    {
        $conversation = (new ConversationStore($this->directory, $this->prompt))->load(self::ID, 'admin');
        $conversation->record(new LlmCall('qwen', 'qwen-plus', ['input' => 10, 'output' => 10], null, 5));

        self::assertFalse($conversation->isCostComplete());
        self::assertSame(0.0, $conversation->costUsd());
    }
}
