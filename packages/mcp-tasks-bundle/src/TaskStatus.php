<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle;

/**
 * Where a task is in its life.
 */
enum TaskStatus: string
{
    /** Created, its reading waits for a human: nothing runs. */
    case Draft = 'draft';

    /** Validated, its first step is in the queue. */
    case Queued = 'queued';

    /** A step is being played by the worker. */
    case Running = 'running';

    /** Stopped on an error. It does not resume: what it wrote stays, a new task is created. */
    case Suspended = 'suspended';

    case Done = 'done';
    case Cancelled = 'cancelled';
}
