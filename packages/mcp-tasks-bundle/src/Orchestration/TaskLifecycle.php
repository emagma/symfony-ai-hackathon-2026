<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Orchestration;

use Emagma\McpTasksBundle\Delegation\DelegationInterface;
use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskNotifierInterface;
use Emagma\McpTasksBundle\TaskStatus;
use Psr\Clock\ClockInterface;

/**
 * What an orchestrator tells the bundle about a task as a whole. The state the owner sees stays the bundle's.
 */
final readonly class TaskLifecycle
{
    public const string INTERRUPTED = 'interrupted: the worker stopped during this step. The last call may have been written without its answer being read (get_task detail: "calls").';

    public function __construct(
        private TaskStoreInterface $store,
        private DelegationInterface $delegation,
        private TaskNotifierInterface $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The last step is done: `done`, and the owner is told. Nothing while a step is in flight, nor before the
     * last step was played.
     */
    public function finished(string $taskId): void
    {
        $task = $this->store->find($taskId);
        if (null === $task || $task->isStepInFlight() || $task->getClaimedStep() !== \count($task->getSteps()) - 1) {
            return;
        }
        if (!$this->store->transition($taskId, TaskStatus::Running, TaskStatus::Done)) {
            return;
        }
        $task->setFinishedAt($this->clock->now());
        $this->store->save();
        $this->tell($task, $this->notifier->finished(...));
    }

    /**
     * The orchestrator lost a step in flight: the task is suspended, never played again, since its step may have
     * written.
     */
    public function interrupted(string $taskId): void
    {
        $task = $this->store->find($taskId);
        if (null === $task || !$this->store->transition($taskId, TaskStatus::Running, TaskStatus::Suspended)) {
            return;
        }
        $task->setError(self::INTERRUPTED);
        $task->setFinishedAt($this->clock->now());
        $task->setStepInFlight(false);
        $this->store->save();
        $this->tell($task, $this->notifier->suspended(...));
    }

    /**
     * True between the claim of a step and its end, as the database holds it now.
     */
    public function isStepInFlight(string $taskId): bool
    {
        return $this->store->find($taskId)?->isStepInFlight() ?? false;
    }

    /**
     * As the owner, for a moment: the notifier finds their address in the security token.
     *
     * @param \Closure(Task): void $notify
     */
    private function tell(Task $task, \Closure $notify): void
    {
        try {
            $this->delegation->actAs((string) $task->getOwner());
        } catch (\Throwable) {
            // Telling is a courtesy: the state of the task is written either way, the owner asks in the chat.
            return;
        }

        try {
            $notify($task);
        } finally {
            $this->delegation->release();
        }
    }
}
