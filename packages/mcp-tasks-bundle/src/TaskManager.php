<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle;

use Emagma\McpTasksBundle\Delegation\DelegationInterface;
use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Orchestration\TaskOrchestratorInterface;
use Emagma\McpTasksBundle\Reading\ReadingFailed;
use Emagma\McpTasksBundle\Reading\ScriptReaderInterface;
use Emagma\McpTasksBundle\Runner\ToolInvokerInterface;
use Emagma\McpTasksBundle\Script\ScriptInspector;
use Emagma\McpTasksBundle\Script\ScriptLinter;
use Emagma\McpTasksBundle\Script\ScriptRefused;
use Emagma\McpTasksBundle\Script\TaskFiles;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates, launches and cancels the tasks; the orchestrator plays their steps. A launched task is never deleted:
 * its scripts and the journal of its calls stay. Only a draft gives way to the next draft of its owner.
 */
final readonly class TaskManager
{
    public const int MAX_STEPS = 50;

    /** A task prepares nothing and launches nothing: these are a human's to call, in a conversation. */
    private const array OWN_TOOLS = ['create_task', 'start_task', 'list_tasks', 'get_task', 'cancel_task', 'revoke_task_authorization'];

    public function __construct(
        private TaskStoreInterface $store,
        private TaskFiles $files,
        private ScriptInspector $inspector,
        private ToolInvokerInterface $tools,
        private ScriptReaderInterface $reader,
        private DelegationInterface $delegation,
        private TaskOrchestratorInterface $orchestrator,
        private ClockInterface $clock,
        private ?ScriptLinter $linter = null,
    ) {
    }

    /**
     * @param array<mixed>               $steps   the steps as the model sent them: `{name, script}` each
     * @param array<string, string>|null $renamed set to the tools renamed: the name written => the tool of this server
     *
     * @param-out array<string, string> $renamed
     *
     * @throws ScriptRefused
     * @throws ReadingFailed
     */
    public function create(string $owner, string $objective, string $recap, string $params, array $steps, ?array &$renamed = null): Task
    {
        $renamed = [];
        $steps = self::steps($steps);
        $id = Uuid::v4()->toRfc4122();
        $names = array_column($steps, 'name');

        // Written first (with <?php and strict mode), so that a configured linter fixes them in place; whatever
        // refuses the task afterwards, its files are removed.
        $this->files->write($id, self::opened($params), array_map(self::opened(...), array_column($steps, 'script')));
        $stored = false;

        try {
            $this->linter?->lint($this->files->directory($id));
            $params = $this->files->params($id);
            $scripts = array_map(fn (int $index): string => $this->files->script($id, $index), array_keys($names));

            $this->inspector->assertParams($params);
            // A tool named as a client shows it (shop_search_products, mcp__shop__search_products) is the tool of
            // this server it ends with: renamed before the reading, so that the human validates the real names.
            $renamed = $this->resolve($this->inspector->manifest($scripts));
            if ([] !== $renamed) {
                $scripts = array_map(fn (string $script): string => $this->inspector->renameTools($script, $renamed), $scripts);
                $this->files->write($id, $params, $scripts);
            }
            $manifest = $this->inspector->manifest($scripts);

            // Only the code is read, after it was accepted: a refused script is never sent to a model. The id comes
            // first: shown with the reading, it is what the human and the model name the task by.
            $steps = array_map(static fn (string $name, string $script): array => ['name' => $name, 'script' => $script], $names, $scripts);
            $reading = \sprintf("Task %s\n\n%s", $id, $this->reader->read($objective, $params, $steps));

            // One draft per user: the one being discussed.
            foreach ($this->store->findByOwner($owner, TaskStatus::Draft) as $draft) {
                $this->remove($draft);
            }

            $task = new Task($id, $owner, $objective, $recap, $reading, $manifest, $names, $this->clock->now());
            $this->store->create($task);
            $stored = true;

            return $task;
        } finally {
            if (!$stored) {
                $this->files->delete($id);
            }
        }
    }

    /**
     * Where the owner must authorize the server first, or null when the task could be launched now.
     */
    public function authorizationUrl(Task $task): ?string
    {
        $owner = (string) $task->getOwner();

        return $this->delegation->isAuthorized($owner) ? null : $this->delegation->authorizationUrl($owner);
    }

    /**
     * @return string|null where the owner must authorize the server first; null when the task is launched
     */
    public function start(Task $task): ?string
    {
        $owner = (string) $task->getOwner();
        if (!$this->delegation->isAuthorized($owner)) {
            return $this->delegation->authorizationUrl($owner);
        }
        if ($this->store->transition($task->getId(), TaskStatus::Draft, TaskStatus::Queued)) {
            $this->orchestrator->start($task);
        }

        return null;
    }

    /**
     * False when the task was already over. A running task stops at its next tool call; the orchestrator of a
     * launched task plays nothing more of it.
     */
    public function cancel(Task $task): bool
    {
        foreach ([TaskStatus::Running, TaskStatus::Queued, TaskStatus::Suspended, TaskStatus::Draft] as $from) {
            if ($this->store->transition($task->getId(), $from, TaskStatus::Cancelled)) {
                $task->setFinishedAt($this->clock->now());
                $this->store->save();
                // A draft was never handed to the orchestrator.
                if (TaskStatus::Draft !== $from) {
                    $this->orchestrator->cancel($task);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * The tools of the manifest this server knows under another name, and a refusal naming every tool it
     * cannot call at once.
     *
     * @param list<string> $manifest
     *
     * @return array<string, string> the name written => the tool of this server
     */
    private function resolve(array $manifest): array
    {
        $available = array_values(array_diff($this->tools->tools(), self::OWN_TOOLS));
        $renamed = [];
        $faults = [];
        foreach ($manifest as $tool) {
            if (\in_array($tool, $available, true)) {
                continue;
            }
            // The longest tool the name ends with, after a separator: shop_search_products is search_products.
            $match = null;
            foreach ($available as $candidate) {
                if (str_ends_with($tool, '_' . $candidate) && \strlen($candidate) > \strlen($match ?? '')) {
                    $match = $candidate;
                }
            }
            if (null !== $match && !\in_array($tool, self::OWN_TOOLS, true)) {
                $renamed[$tool] = $match;

                continue;
            }
            $close = array_values(array_filter($available, static fn (string $candidate): bool => levenshtein($tool, $candidate) <= 3 || str_contains($candidate, $tool) || str_contains($tool, $candidate)));
            $faults[] = \sprintf('"%s"%s', $tool, [] === $close ? '' : ' (did you mean ' . implode(', ', \array_slice($close, 0, 3)) . '?)');
        }
        if ([] !== $faults) {
            throw new ScriptRefused('tool_not_allowed', 'These tools do not exist on this server, or you may not call them: ' . implode('; ', $faults) . '. Name a tool as this server does, without the prefix your client may add.');
        }

        return $renamed;
    }

    /**
     * The opening tag a model may forget: a script or the params without it start with it.
     */
    private static function opened(string $code): string
    {
        return str_starts_with(ltrim($code), '<?php') ? ltrim($code) : "<?php\n" . $code;
    }

    private function remove(Task $task): void
    {
        $this->files->delete($task->getId());
        $this->store->delete($task->getId());
    }

    /**
     * @param array<mixed> $steps
     *
     * @return non-empty-list<array{name: string, script: string}>
     */
    private static function steps(array $steps): array
    {
        if ([] === $steps || \count($steps) > self::MAX_STEPS) {
            throw new ScriptRefused('steps_invalid', \sprintf('A task has 1 to %d steps.', self::MAX_STEPS));
        }
        $valid = [];
        foreach (array_values($steps) as $index => $step) {
            $name = \is_array($step) ? ($step['name'] ?? null) : null;
            $script = \is_array($step) ? ($step['script'] ?? null) : null;
            if (!\is_string($name) || 1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $name) || !\is_string($script) || '' === trim($script)) {
                throw new ScriptRefused('steps_invalid', \sprintf('Step %d: each step is {name, script}, the name in lowercase letters, digits and dashes.', $index));
            }
            $valid[] = ['name' => $name, 'script' => $script];
        }

        return $valid;
    }
}
