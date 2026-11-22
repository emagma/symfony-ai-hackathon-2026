<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Testing;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Entity\TaskCall;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskStatus;

/**
 * For the suites of the bundles that create tasks.
 *
 * @internal
 */
final class InMemoryTaskStore implements TaskStoreInterface
{
    /** @var array<string, Task> */
    public array $tasks = [];

    /** @var list<TaskCall> */
    public array $calls = [];

    public function create(Task $task): void
    {
        $this->tasks[$task->getId()] = $task;
    }

    public function find(string $id): ?Task
    {
        return $this->tasks[$id] ?? null;
    }

    public function findByOwner(?string $owner, ?TaskStatus $status = null, int $limit = 50): array
    {
        $tasks = array_filter(
            $this->tasks,
            static fn (Task $task): bool => $task->getOwner() === $owner && (null === $status || $task->getStatus() === $status),
        );
        usort($tasks, static fn (Task $a, Task $b): int => [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()]);

        return \array_slice($tasks, 0, $limit);
    }

    public function findByStatus(TaskStatus $status): array
    {
        return array_values(array_filter($this->tasks, static fn (Task $task): bool => $task->getStatus() === $status));
    }

    public function status(string $id): ?TaskStatus
    {
        return $this->find($id)?->getStatus();
    }

    public function transition(string $id, TaskStatus $from, TaskStatus $to): bool
    {
        $task = $this->find($id);
        if (null === $task || $task->getStatus() !== $from) {
            return false;
        }
        $task->setStatus($to);

        return true;
    }

    public function claim(string $id, int $step): bool
    {
        $task = $this->find($id);
        if (null === $task || $task->getClaimedStep() >= $step || !\in_array($task->getStatus(), [TaskStatus::Queued, TaskStatus::Running], true)) {
            return false;
        }
        $task->setClaimedStep($step);

        return true;
    }

    public function addCall(TaskCall $call): void
    {
        $this->calls[] = $call;
    }

    public function calls(string $taskId, int $limit = 50): array
    {
        $calls = array_values(array_filter($this->calls, static fn (TaskCall $call): bool => $call->getTaskId() === $taskId));

        return \array_slice(array_reverse($calls), 0, $limit);
    }

    public function save(): void
    {
    }

    public function delete(string $id): void
    {
        unset($this->tasks[$id]);
        $this->calls = array_values(array_filter($this->calls, static fn (TaskCall $call): bool => $call->getTaskId() !== $id));
    }
}
