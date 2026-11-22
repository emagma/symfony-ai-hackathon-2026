<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Testing;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\TaskNotifierInterface;

/**
 * A notifier that records the tasks it was told about, for the suites of the bundles that create tasks.
 *
 * @internal
 */
final class RecordingTaskNotifier implements TaskNotifierInterface
{
    /** @var list<string> */
    public array $finished = [];

    /** @var list<string> */
    public array $suspended = [];

    public function finished(Task $task): void
    {
        $this->finished[] = $task->getId();
    }

    public function suspended(Task $task): void
    {
        $this->suspended[] = $task->getId();
    }
}
