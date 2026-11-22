<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Orchestration;

use Emagma\McpTasksBundle\Delegation\DelegationInterface;
use Emagma\McpTasksBundle\Delegation\DelegationRevoked;
use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Runner\StepResult;
use Emagma\McpTasksBundle\Runner\StepRunner;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskNotifierInterface;
use Emagma\McpTasksBundle\TaskStatus;
use Psr\Clock\ClockInterface;

/**
 * Plays one step, as the owner of the task, wherever the orchestrator runs it. It never plays the next step and
 * never ends the task: that is the orchestrator's, through TaskLifecycle.
 */
final readonly class StepExecutor
{
    public function __construct(
        private TaskStoreInterface $store,
        private StepRunner $runner,
        private DelegationInterface $delegation,
        private TaskNotifierInterface $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param int $step the index of the step, from 0
     */
    public function execute(string $taskId, int $step): StepOutcome
    {
        $task = $this->store->find($taskId);
        if (null === $task || !$this->playable($task, $step) || !$this->store->claim($taskId, $step)) {
            return StepOutcome::Skipped;
        }
        if (0 === $step) {
            if (!$this->store->transition($taskId, TaskStatus::Queued, TaskStatus::Running)) {
                return StepOutcome::Skipped;
            }
            $task->setStartedAt($this->clock->now());
        }
        $task->setCurrentStep($step);
        $task->setStepInFlight(true);
        $this->store->save();

        try {
            return $this->play($task, $step);
        } finally {
            // Whatever happened, the step is no longer in flight: a stop of the worker now loses nothing.
            $task->setStepInFlight(false);
            $this->store->save();
        }
    }

    /**
     * A step that does not exist, one played twice or out of order, or one of a task cancelled meanwhile plays
     * nothing. claim() makes the check final: a step delivered twice at the same moment is played once.
     */
    private function playable(Task $task, int $step): bool
    {
        if ($step < 0 || $step >= \count($task->getSteps()) || $task->isStepInFlight() || $task->getClaimedStep() !== $step - 1) {
            return false;
        }

        return $task->getStatus() === (0 === $step ? TaskStatus::Queued : TaskStatus::Running);
    }

    private function play(Task $task, int $step): StepOutcome
    {
        try {
            $this->delegation->actAs((string) $task->getOwner());
        } catch (DelegationRevoked $revoked) {
            return $this->suspend($task, 'delegation_revoked: ' . $revoked->getMessage());
        }

        try {
            try {
                $result = $this->runner->run($task, $step);
            } catch (\Throwable $exception) {
                // Whatever broke in the worker itself: the task must not stay running for ever.
                $result = StepResult::failed('The worker failed: ' . $exception->getMessage());
            }
            if (!$result->ok) {
                // Still as the owner: the notifier finds their address in the security token.
                return $this->suspend($task, (string) $result->error);
            }
        } finally {
            $this->delegation->release();
        }

        // Cancelled after its last call: the step is over, the task plays nothing more.
        if (TaskStatus::Running !== $this->store->status($task->getId())) {
            return StepOutcome::Skipped;
        }
        // The progress names the step that comes next while it waits for the orchestrator.
        if ($step + 1 < \count($task->getSteps())) {
            $task->setCurrentStep($step + 1);
        }

        return StepOutcome::Done;
    }

    private function suspend(Task $task, string $error): StepOutcome
    {
        // A task cancelled meanwhile is cancelled, not suspended: the stop was asked for.
        if (!$this->store->transition($task->getId(), TaskStatus::Running, TaskStatus::Suspended)) {
            return StepOutcome::Skipped;
        }
        $task->setError($error);
        $task->setFinishedAt($this->clock->now());
        $task->setStepInFlight(false);
        $this->store->save();
        $this->notifier->suspended($task);

        return StepOutcome::Failed;
    }
}
