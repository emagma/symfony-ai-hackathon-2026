<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Entity\TaskCall;
use Emagma\McpTasksBundle\Script\TaskFiles;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;

/**
 * A task as the tools answer it.
 *
 * @internal
 */
final readonly class TaskView
{
    public function __construct(
        private TaskStoreInterface $store,
        private TaskFiles $files,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Task $task): array
    {
        return [
            'taskId' => $task->getId(),
            'objective' => $task->getObjective(),
            'status' => $task->getStatus()->value,
            'progress' => $this->progress($task),
            'createdAt' => $task->getCreatedAt()->format(\DATE_ATOM),
            'finishedAt' => $task->getFinishedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Task $task): array
    {
        $detail = $this->summary($task) + [
            'recap' => $task->getRecap(),
            'reading' => $task->getReading(),
            'manifest' => $task->getManifest(),
            'startedAt' => $task->getStartedAt()?->format(\DATE_ATOM),
        ];
        if (null !== $task->getError()) {
            $detail['error'] = $task->getError();
        }
        $detail['next'] = match ($task->getStatus()) {
            TaskStatus::Draft => 'Nothing runs. Show the reading to the human; start_task launches it, a new create_task replaces it.',
            TaskStatus::Queued => 'Launched, waiting for the worker. get_task again later, cancel_task to stop it.',
            TaskStatus::Running => 'Running: progress is its activity, not a percentage. Several minutes without a call mean it is stuck. cancel_task stops it at its next tool call.',
            TaskStatus::Suspended => 'Stopped on the error above, in the step progress names. What it wrote stays written (get_task detail: "calls"). It does not resume: prepare a new task for what remains.',
            TaskStatus::Done => 'Over. The task, its scripts and its calls stay readable.',
            TaskStatus::Cancelled => 'Stopped on request. What it wrote before stays written.',
        };

        return $detail;
    }

    /**
     * @return array{params: string, steps: list<array{name: string, script: string}>}
     */
    public function scripts(Task $task): array
    {
        $steps = [];
        foreach ($task->getSteps() as $index => $name) {
            $steps[] = ['name' => $name, 'script' => $this->files->script($task->getId(), $index)];
        }

        return ['params' => $this->files->params($task->getId()), 'steps' => $steps];
    }

    /**
     * @return list<array<string, mixed>> the most recent first
     */
    public function calls(Task $task, int $limit): array
    {
        return array_map(static fn (TaskCall $call): array => array_filter([
            'step' => $call->getStep() + 1,
            'tool' => $call->getTool(),
            'arguments' => $call->getArguments(),
            'outcome' => $call->getOutcome(),
            'error' => $call->getError(),
            // Neither an outcome nor an error: the worker stopped during the call, a write may have gone out.
            'uncertain' => $call->isOver() ? null : true,
            'at' => $call->getStartedAt()->format(\DATE_ATOM),
            'durationMs' => $call->getDurationMs(),
        ], static fn (mixed $value): bool => null !== $value), $this->store->calls($task->getId(), $limit));
    }

    /**
     * The step is the only progress the server can vouch for; the calls show that the task is alive.
     *
     * @return array<string, mixed>
     */
    private function progress(Task $task): array
    {
        $steps = $task->getSteps();

        return [
            'step' => $task->getCurrentStep() + 1,
            'steps' => \count($steps),
            'name' => $steps[$task->getCurrentStep()] ?? null,
            'calls' => $task->getCalls(),
            'lastTool' => $task->getLastTool(),
            'lastCallAt' => $task->getLastCallAt()?->format(\DATE_ATOM),
        ];
    }
}
