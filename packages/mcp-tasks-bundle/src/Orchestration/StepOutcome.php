<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Orchestration;

/**
 * What playing one step came to, for the orchestrator that asked.
 */
enum StepOutcome
{
    /** The step finished: the orchestrator plays the next one, or ends the task with TaskLifecycle::finished(). */
    case Done;

    /** The task is suspended: the bundle did it and told the owner. Play nothing more. */
    case Failed;

    /** Already claimed, out of order, cancelled, or not running: play nothing more. */
    case Skipped;
}
