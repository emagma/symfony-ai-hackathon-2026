<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Schedule;

use Emagma\McpAgentsBundle\EmagmaMcpAgentsBundle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The safety net of the agents: an event lost, a worker down. Every agent with an `every()` gets a `RunAgent` at that
 * pace, redispatched to `mcp_agents`: the process that consumes `scheduler_mcp_agents` is a clock, it runs no agent.
 *
 * - Stateful: the last run is kept in the cache pool `emagma_mcp_agents.schedule`, across restarts. After a stop, only the
 *   last missed run is played.
 * - Locked: two processes that consume the schedule emit each tick once.
 *
 * @internal
 */
#[AsSchedule(self::NAME)]
final readonly class AgentsSchedule implements ScheduleProviderInterface
{
    public const string NAME = 'mcp_agents';

    /** The lock the processes of the schedule share. */
    public const string LOCK = 'emagma_mcp_agents.schedule';

    /**
     * @param ServiceProviderInterface<ScheduledAgentInterface> $agents the agents by name
     * @param CacheInterface                                    $state  the cache pool `emagma_mcp_agents.schedule`
     */
    public function __construct(
        private ServiceProviderInterface $agents,
        private CacheInterface $state,
        private LockFactory $locks,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = (new Schedule())
            ->stateful($this->state)
            ->lock($this->locks->createLock(self::LOCK))
            ->processOnlyLastMissedRun(true)
        ;
        foreach (array_keys($this->agents->getProvidedServices()) as $name) {
            $every = $this->agents->get($name)->every();
            if (null !== $every) {
                $schedule->add(RecurringMessage::every($every, new RedispatchMessage(new RunAgent($name), EmagmaMcpAgentsBundle::TRANSPORT)));
            }
        }

        return $schedule;
    }
}
