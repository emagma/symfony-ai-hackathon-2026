<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Orchestration;

use Emagma\McpTasksBundle\Entity\Task;

/**
 * Decides which step of a task runs, and when: a queue, a workflow, a job. Each step is played on the server
 * by StepExecutor; the orchestrator never holds the owner's token nor the scripts.
 */
interface TaskOrchestratorInterface
{
    /**
     * Takes the flow over. The task is already `queued`; play step 0 when you can.
     */
    public function start(Task $task): void;

    /**
     * Plays nothing more of this task. The step in flight stops at its next tool call.
     */
    public function cancel(Task $task): void;
}
