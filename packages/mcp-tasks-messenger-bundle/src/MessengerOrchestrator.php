<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Orchestration\TaskOrchestratorInterface;
use Emagma\McpTasksMessengerBundle\Message\RunStep;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One message per step on the `mcp_tasks` queue: the next step is another message, so that a worker can stop
 * between two steps.
 *
 * @internal
 */
final readonly class MessengerOrchestrator implements TaskOrchestratorInterface
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function start(Task $task): void
    {
        $this->bus->dispatch(new RunStep($task->getId(), 0));
    }

    public function cancel(Task $task): void
    {
        // Nothing to withdraw: the step in flight stops at its next tool call, the next message plays nothing.
    }
}
