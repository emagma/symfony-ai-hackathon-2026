<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Schedule;

/**
 * "Run this agent once": put in the `mcp_agents` transport by `AgentRunner::trigger()` and by the schedule, handled
 * by the worker of that transport.
 */
final readonly class RunAgent
{
    public function __construct(
        public string $name,
    ) {
    }
}
