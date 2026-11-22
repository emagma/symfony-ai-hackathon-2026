<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Store;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Entity\TaskCall;
use Emagma\McpTasksBundle\TaskStatus;

/**
 * Where the tasks and the journal of their calls are kept.
 */
interface TaskStoreInterface
{
    public function create(Task $task): void;

    public function find(string $id): ?Task;

    /**
     * @return list<Task> the most recent first
     */
    public function findByOwner(?string $owner, ?TaskStatus $status = null, int $limit = 50): array;

    /**
     * @return list<Task>
     */
    public function findByStatus(TaskStatus $status): array;

    /**
     * The status as the database holds it now: a cancellation comes from another process.
     */
    public function status(string $id): ?TaskStatus;

    /**
     * Moves the task from one status to another, and only from that one: false when a cancellation or the
     * worker got there first.
     */
    public function transition(string $id, TaskStatus $from, TaskStatus $to): bool;

    /**
     * Takes a step for this worker, once: false when the step, or a later one, was already taken, or when
     * the task is neither queued nor running.
     */
    public function claim(string $id, int $step): bool;

    public function addCall(TaskCall $call): void;

    /**
     * @return list<TaskCall> the most recent first
     */
    public function calls(string $taskId, int $limit = 50): array;

    /**
     * Writes what changed on the tasks and the calls it handed out.
     */
    public function save(): void;

    public function delete(string $id): void;
}
