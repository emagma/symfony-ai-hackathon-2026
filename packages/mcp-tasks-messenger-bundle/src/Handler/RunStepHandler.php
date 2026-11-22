<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Handler;

use Emagma\McpTasksBundle\Orchestration\StepExecutor;
use Emagma\McpTasksBundle\Orchestration\StepOutcome;
use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksMessengerBundle\Message\RunStep;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One message, one step. A step done sends the message of the next one, or ends the task after the last.
 *
 * @internal
 */
final readonly class RunStepHandler
{
    public function __construct(
        private StepExecutor $executor,
        private TaskLifecycle $lifecycle,
        private TaskStoreInterface $store,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(RunStep $message): void
    {
        // Failed or skipped: the bundle already set the state of the task, nothing more is played.
        if (StepOutcome::Done !== $this->executor->execute($message->taskId, $message->step)) {
            return;
        }
        $steps = \count($this->store->find($message->taskId)?->getSteps() ?? []);
        if ($message->step + 1 < $steps) {
            $this->bus->dispatch(new RunStep($message->taskId, $message->step + 1));

            return;
        }
        $this->lifecycle->finished($message->taskId);
    }
}
