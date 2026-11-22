<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Sandbox;

/**
 * Where the scripts of a task run: somewhere that only has the directory of the task and a dialogue with
 * the worker. A hardened PHP process today; a container would be another implementation.
 */
interface SandboxInterface
{
    /**
     * @param string $taskDirectory absolute path of the task's directory (params.php, steps/)
     * @param float  $timeout       seconds the session may last
     */
    public function open(string $taskDirectory, float $timeout): SandboxSessionInterface;
}
