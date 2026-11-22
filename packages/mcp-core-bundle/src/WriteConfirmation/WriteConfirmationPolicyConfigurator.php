<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\WriteConfirmation;

use Emagma\McpCoreBundle\Authorization\Action;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Re-applies the policy on every request and command: the trait that enforces it cannot be injected, and a long-lived worker must not drift.
 *
 * @internal
 */
final readonly class WriteConfirmationPolicyConfigurator
{
    /**
     * @param list<string> $requireHumanFor
     * @param list<string> $trustedClients
     */
    public function __construct(
        private array $requireHumanFor,
        private array $trustedClients = [],
        private float $autoCancelThreshold = 0.0,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $this->apply();
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->apply();
    }

    public function apply(): void
    {
        WriteConfirmationPolicy::configure(new WriteConfirmationPolicy(
            array_values(array_filter(array_map(
                static fn (string $action): ?Action => Action::tryFrom($action),
                $this->requireHumanFor,
            ))),
            $this->trustedClients,
            $this->autoCancelThreshold,
        ));
    }
}
