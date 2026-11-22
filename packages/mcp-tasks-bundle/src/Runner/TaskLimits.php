<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Runner;

/**
 * What bounds a task whatever its scripts do.
 */
final readonly class TaskLimits
{
    /**
     * @param float $stepTimeout seconds one step may last
     * @param int   $maxCalls    tool calls one task may make, all steps together
     */
    public function __construct(
        public float $stepTimeout = 14400.0,
        public int $maxCalls = 100000,
    ) {
    }
}
