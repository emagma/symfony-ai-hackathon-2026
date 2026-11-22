<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Conversation;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * One file per conversation under var/conversations/. Not the session: a turn
 * releases the session before streaming, and conversations outlive a login.
 */
final class ConversationStore
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/conversations')]
        private readonly string $directory,
        #[Autowire('%kernel.project_dir%/prompts/system.md')]
        private readonly string $systemPrompt,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * The conversation, or a new one opened with the system prompt. A
     * conversation belongs to the user who started it.
     */
    public function load(string $id, string $owner): Conversation
    {
        self::assertId($id);
        $path = $this->path($id);

        if (is_file($path)) {
            $conversation = $this->read($path);
            if (null === $conversation) {
                throw new \DomainException('This conversation was saved by an older version of TestChat and cannot be read anymore.');
            }
            if ($conversation->owner !== $owner) {
                throw new \DomainException('This conversation belongs to another user.');
            }

            return $conversation;
        }

        // The system prompt is set once, here: Symfony AI's SystemPromptInputProcessor
        // would add it to the persisted history again on every turn.
        return new Conversation($id, $owner, new MessageBag(Message::forSystem((string) file_get_contents($this->systemPrompt))));
    }

    /**
     * Writes the conversation and, next to it, its summary for the list: the
     * list never unserializes the histories, which carry every tool schema.
     */
    public function save(Conversation $conversation): void
    {
        $conversation->touch();
        $this->filesystem->dumpFile($this->path($conversation->id), serialize($conversation));
        $this->writeSummary($conversation);
    }

    /**
     * The user's conversations, most recently used first.
     *
     * @return list<array{id: string, owner: string, title: string, created_at: string|null, updated_at: string|null, models: list<string>, cost_usd: float, cost_complete: bool, calls: int}>
     */
    public function list(string $owner, int $limit = 100): array
    {
        $this->backfillSummaries();

        $summaries = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $summary = json_decode((string) file_get_contents($file), true);
            if (\is_array($summary) && ($summary['owner'] ?? null) === $owner) {
                /** @var array{id: string, owner: string, title: string, created_at: string|null, updated_at: string|null, models: list<string>, cost_usd: float, cost_complete: bool, calls: int} $typed */
                $typed = $summary;
                $summaries[] = $typed;
            }
        }

        usort($summaries, static fn (array $a, array $b): int => ($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? ''));

        return \array_slice($summaries, 0, $limit);
    }

    /**
     * @throws \DomainException when the conversation belongs to another user
     */
    public function delete(string $id, string $owner): bool
    {
        self::assertId($id);
        if (!is_file($this->path($id))) {
            return false;
        }

        // load() refuses another user's conversation.
        $this->load($id, $owner);
        $this->filesystem->remove([$this->path($id), $this->summaryPath($id)]);

        return true;
    }

    private function writeSummary(Conversation $conversation): void
    {
        $this->filesystem->dumpFile($this->summaryPath($conversation->id), (string) json_encode([
            'id' => $conversation->id,
            'owner' => $conversation->owner,
            'title' => $conversation->title(),
            'created_at' => $conversation->createdAt()?->format(\DATE_ATOM),
            'updated_at' => $conversation->updatedAt()?->format(\DATE_ATOM),
            'models' => $conversation->models(),
            'cost_usd' => $conversation->costUsd(),
            'cost_complete' => $conversation->isCostComplete(),
            'calls' => \count($conversation->calls()),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Conversations saved before summaries existed get theirs once, dated by
     * their file.
     */
    private function backfillSummaries(): void
    {
        foreach (glob($this->directory . '/*.ser') ?: [] as $file) {
            $id = basename($file, '.ser');
            if (is_file($this->summaryPath($id))) {
                continue;
            }

            $conversation = $this->read($file);
            if (null !== $conversation) {
                $this->writeSummary($conversation);
                if (null === $conversation->updatedAt()) {
                    $summary = (array) json_decode((string) file_get_contents($this->summaryPath($id)), true);
                    $summary['created_at'] = $summary['updated_at'] = date(\DATE_ATOM, (int) filemtime($file));
                    $this->filesystem->dumpFile($this->summaryPath($id), (string) json_encode($summary, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
                }
            }
        }
    }

    public static function assertId(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            throw new \InvalidArgumentException('A conversation id is a lowercase UUID.');
        }
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . $id . '.ser';
    }

    /**
     * null when the file predates a change of the classes it holds: an old
     * conversation must never take the list or the page down.
     */
    private function read(string $path): ?Conversation
    {
        try {
            $conversation = @unserialize((string) file_get_contents($path), ['allowed_classes' => true]);
        } catch (\Throwable) {
            return null;
        }

        return $conversation instanceof Conversation ? $conversation : null;
    }

    private function summaryPath(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }
}
