<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle;

use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The one way to ask for a pass of a background agent, and the one way to play it.
 *
 * - `trigger()` puts `RunAgent` in the `mcp_agents` transport, unless one for that agent is already waiting there.
 *   Its key is released when the worker receives the message, before the pass starts: whatever happens during the
 *   pass asks for another one, no trigger is lost.
 * - `run()` plays a pass now, under a lock of the agent: two passes of one agent never overlap. A pass that does
 *   not get the lock is skipped, not queued: the pass that holds it reads what is new.
 */
final readonly class AgentRunner
{
    /** How long a waiting `RunAgent` keeps the others out: bounds a key left behind by a worker that died. */
    public const float QUEUED_TTL = 3600.0;

    /** How long a pass may hold its lock: bounds a lock left behind by a worker that died. */
    public const float PASS_TTL = 3600.0;

    /**
     * @param ServiceProviderInterface<ScheduledAgentInterface> $agents the agents by name
     */
    public function __construct(
        private MessageBusInterface $bus,
        private LockFactory $locks,
        private ServiceProviderInterface $agents,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The key of the deduplication of the agent's `RunAgent` waiting in the queue.
     */
    public static function queuedKey(string $name): string
    {
        return 'mcp_agent.queued.' . $name;
    }

    /**
     * The key of the lock a pass of the agent holds. Not the queued key: a trigger during a pass must go through.
     */
    public static function passKey(string $name): string
    {
        return 'mcp_agent.run.' . $name;
    }

    public function has(string $name): bool
    {
        return $this->agents->has($name);
    }

    /**
     * Asks for a pass: `RunAgent` in `mcp_agents`, nothing more when one is already waiting.
     *
     * @throws \InvalidArgumentException when no agent has that name
     */
    public function trigger(string $name): void
    {
        // has(), not get(): the webhook's request asks for a pass, it builds no agent.
        if (!$this->agents->has($name)) {
            throw new \InvalidArgumentException(\sprintf('No background agent is named "%s".', $name));
        }

        // A new stamp every time: its key carries the token of the lock it takes.
        $this->bus->dispatch(new RunAgent($name), [new DeduplicateStamp(self::queuedKey($name), self::QUEUED_TTL, onlyDeduplicateInQueue: true)]);
    }

    /**
     * One pass now, under the agent's lock.
     *
     * @return array<string, int>|null the agent's summary; null when another pass holds the lock, and this one was skipped
     *
     * @throws \InvalidArgumentException when no agent has that name
     */
    public function run(string $name): ?array
    {
        $agent = $this->agent($name);

        $lock = $this->locks->createLock(self::passKey($name), self::PASS_TTL);
        if (!$lock->acquire()) {
            $this->logger?->info('A pass of the background agent "{agent}" is running: this one is skipped.', ['agent' => $name]);

            return null;
        }

        try {
            return $agent->run();
        } finally {
            $lock->release();
        }
    }

    private function agent(string $name): ScheduledAgentInterface
    {
        if (!$this->agents->has($name)) {
            throw new \InvalidArgumentException(\sprintf('No background agent is named "%s".', $name));
        }

        return $this->agents->get($name);
    }
}
