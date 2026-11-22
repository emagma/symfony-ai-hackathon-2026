<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\EventListener;

use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * A bundle cannot declare a firewall: it logs, once per process, when allow-all grants are served over HTTP.
 * Logged, not thrown: the host's security configuration is not the bundle's to veto.
 *
 * @internal
 */
final class AllowAllGrantsWarningListener
{
    private bool $reported = false;

    public function __construct(
        private readonly GrantsProviderInterface $grantsProvider,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($this->reported || !$event->isMainRequest() || null === $this->logger) {
            return;
        }

        if (!$this->grantsProvider->grants()->isAllowAll()) {
            return;
        }

        $this->reported = true;
        $this->logger->critical(
            'MCP authorization is disabled (allow-all grants) on an HTTP transport: every MCP tool,'
            . ' including the destructive ones, is available to anyone who can reach the endpoint.'
            . ' AllowAllGrantsProvider is meant for stdio and local development. Wire a real'
            . ' GrantsProviderInterface and put the MCP path behind a firewall.',
            ['path' => $event->getRequest()->getPathInfo()],
        );
    }
}
