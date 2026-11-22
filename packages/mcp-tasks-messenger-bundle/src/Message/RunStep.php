<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Message;

/**
 * Play one step of a task. Never retried: a step that wrote must not write twice.
 *
 * Stored serialized by the database transports: its class name and namespace must not change.
 */
final readonly class RunStep
{
    public function __construct(
        public string $taskId,
        public int $step,
    ) {
    }
}
