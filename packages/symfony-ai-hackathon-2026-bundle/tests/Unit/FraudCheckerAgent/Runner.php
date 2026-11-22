<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Unit\FraudCheckerAgent;

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The runner of the agents over a bus that keeps what it is given and a lock store in memory.
 */
final class Runner implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public readonly LockFactory $locks;

    public readonly AgentRunner $runner;

    public function __construct(ScheduledAgentInterface $agent)
    {
        $this->locks = new LockFactory(new InMemoryStore());
        $this->runner = new AgentRunner($this, $this->locks, new ServiceLocator([$agent::name() => static fn (): ScheduledAgentInterface => $agent]));
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return Envelope::wrap($message, $stamps);
    }
}
