<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit\Support;

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\EmagmaMcpAgentsBundle;
use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DeduplicateMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The agents by name, a lock store, and the bus of the server in miniature: the deduplication middleware in front of
 * the transport `mcp_agents`, serialized as a real queue would.
 */
final class Agents
{
    public readonly LockFactory $locks;

    public readonly InMemoryTransport $transport;

    public readonly MessageBus $bus;

    /** @var ServiceLocator<ScheduledAgentInterface> */
    public readonly ServiceLocator $agents;

    public function __construct(ScheduledAgentInterface ...$agents)
    {
        $this->locks = new LockFactory(new InMemoryStore());
        $transport = $this->transport = new InMemoryTransport(new PhpSerializer());
        $this->bus = new MessageBus([
            new DeduplicateMiddleware($this->locks),
            new SendMessageMiddleware(new SendersLocator(
                [RunAgent::class => [EmagmaMcpAgentsBundle::TRANSPORT]],
                new ServiceLocator([EmagmaMcpAgentsBundle::TRANSPORT => static fn (): InMemoryTransport => $transport]),
            )),
        ]);
        $this->agents = self::locator(...$agents);
    }

    /**
     * @return ServiceLocator<ScheduledAgentInterface>
     */
    public static function locator(ScheduledAgentInterface ...$agents): ServiceLocator
    {
        $factories = [];
        foreach ($agents as $agent) {
            $factories[$agent::name()] = static fn (): ScheduledAgentInterface => $agent;
        }

        return new ServiceLocator($factories);
    }

    public function runner(): AgentRunner
    {
        return new AgentRunner($this->bus, $this->locks, $this->agents);
    }

    /**
     * The messages waiting in `mcp_agents`.
     *
     * @return list<Envelope>
     */
    public function waiting(): array
    {
        // Not get(): from Symfony 8.1 it returns one message per call, like a real transport.
        $gone = [];
        foreach ([...$this->transport->getAcknowledged(), ...$this->transport->getRejected()] as $envelope) {
            $gone[] = $envelope->last(TransportMessageIdStamp::class)?->getId();
        }

        return array_values(array_filter(
            $this->transport->getSent(),
            static fn (Envelope $envelope): bool => !\in_array($envelope->last(TransportMessageIdStamp::class)?->getId(), $gone, true),
        ));
    }

    /**
     * What a worker does with a message it receives, before its handler: the bus, with a ReceivedStamp.
     */
    public function receive(Envelope $envelope): void
    {
        $this->transport->ack($envelope);
        $this->bus->dispatch($envelope->with(new ReceivedStamp(EmagmaMcpAgentsBundle::TRANSPORT)));
    }
}
