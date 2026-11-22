<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Schedule;

use Emagma\McpAgentsBundle\AgentRunner;
use Psr\Log\LoggerInterface;

/**
 * Plays the pass `RunAgent` asks for, in the worker of `mcp_agents`. A pass of the same agent already running: this
 * one is skipped (`AgentRunner::run()`), and the message is done.
 *
 * @internal
 */
final readonly class RunAgentHandler
{
    public function __construct(
        private AgentRunner $runner,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(RunAgent $message): void
    {
        if (!$this->runner->has($message->name)) {
            // An agent removed since the message was sent: nothing to retry.
            $this->logger?->warning('No background agent is named "{agent}": the message is dropped.', ['agent' => $message->name]);

            return;
        }

        $this->runner->run($message->name);
    }
}
