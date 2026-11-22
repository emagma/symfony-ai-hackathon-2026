<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Schedule;

/**
 * A background agent: run when `AgentRunner::trigger()` asks for it, and on the `mcp_agents` schedule. Tagged
 * `emagma_mcp_agents.agent` by autoconfiguration.
 */
interface ScheduledAgentInterface
{
    /**
     * The agent's name in its reports (`mcp_agent_report.agent`) and in `RunAgent`. Static: two agents with the
     * same name fail when the container is built.
     */
    public static function name(): string;

    /**
     * How often it runs on its own (`'5 minutes'`), or null when it only runs when triggered or by hand.
     */
    public function every(): ?string;

    /**
     * One pass: everything that is new, batch after batch, up to a bound of the agent's own; the next pass catches
     * up with the rest. Never two at once for one agent: `AgentRunner::run()` holds its lock. The summary is what a
     * command prints.
     *
     * @return array<string, int>
     */
    public function run(): array;
}
