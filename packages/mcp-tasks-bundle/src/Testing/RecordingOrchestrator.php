<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Testing;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Orchestration\StepExecutor;
use Emagma\McpTasksBundle\Orchestration\StepOutcome;
use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Orchestration\TaskOrchestratorInterface;

/**
 * For the suites of the bundles that create tasks: records what it is asked, and plays the tasks started when
 * the test says so, step after step, as an orchestrator does.
 *
 * @internal
 */
final class RecordingOrchestrator implements TaskOrchestratorInterface
{
    /** @var list<string> */
    public array $started = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<Task> the tasks started and not played yet */
    private array $pending = [];

    public function start(Task $task): void
    {
        $this->started[] = $task->getId();
        $this->pending[] = $task;
    }

    public function cancel(Task $task): void
    {
        $this->cancelled[] = $task->getId();
    }

    /**
     * Plays the tasks started since the last call: each step once the previous one is done, then the end.
     *
     * @return array<string, list<StepOutcome>> the outcome of each step played, by task
     */
    public function play(StepExecutor $executor, TaskLifecycle $lifecycle): array
    {
        $outcomes = [];
        while (null !== $task = array_shift($this->pending)) {
            $outcomes[$task->getId()] = [];
            foreach (array_keys($task->getSteps()) as $step) {
                $outcome = $executor->execute($task->getId(), $step);
                $outcomes[$task->getId()][] = $outcome;
                if (StepOutcome::Done !== $outcome) {
                    continue 2;
                }
            }
            $lifecycle->finished($task->getId());
        }

        return $outcomes;
    }
}
