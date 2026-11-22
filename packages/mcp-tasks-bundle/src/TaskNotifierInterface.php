<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle;

use Emagma\McpTasksBundle\Entity\Task;

/**
 * Tells the owner of a task what they are not there to see. Called while the worker still acts as them.
 */
interface TaskNotifierInterface
{
    public function finished(Task $task): void;

    public function suspended(Task $task): void;
}
