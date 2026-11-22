<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\EventListener;

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpSyliusEventsBundle\Event\ShopEventRecorded;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\FraudAgent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;

/**
 * What binds the shop's events to the fraud agent: a customer account is created, the agent is asked for a pass.
 * Neither the events bundle nor the agents bundle knows the other. Not enabled, and nothing is asked.
 *
 * @internal
 */
final readonly class NewAccountListener
{
    public function __construct(
        private AgentRunner $runner,
        private bool $enabled = true,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ShopEventRecorded $event): void
    {
        if (!$this->enabled || FraudAgent::EVENT !== $event->name) {
            return;
        }

        try {
            $this->runner->trigger(FraudAgent::AGENT);
        } catch (LockException|MessengerException $exception) {
            // The event is in the journal: the schedule's next pass reads it. The webhook is not failed for that.
            $this->logger?->warning('The fraud agent could not be triggered; its schedule will catch up.', ['sequence' => $event->sequence, 'exception' => $exception]);
        }
    }
}
