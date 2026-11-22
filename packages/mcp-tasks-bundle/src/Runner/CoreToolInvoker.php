<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Runner;

use Emagma\McpCoreBundle\Call\LocalToolCaller;
use Emagma\McpCoreBundle\Call\LocalToolCallFailed;
use Emagma\McpTasksBundle\Delegation\DelegationInterface;
use Emagma\McpTasksBundle\Delegation\DelegationRevoked;

/**
 * The tools of the server, through the core: its registry decides what the current caller may call.
 *
 * @internal
 */
final readonly class CoreToolInvoker implements ToolInvokerInterface
{
    public function __construct(
        private LocalToolCaller $caller,
        private DelegationInterface $delegation,
    ) {
    }

    public function allows(string $tool): bool
    {
        return $this->caller->has($tool);
    }

    public function tools(): array
    {
        return $this->caller->names();
    }

    public function invoke(string $tool, array $arguments): array
    {
        try {
            // No-op in a request; in the worker, the owner's rights of now.
            $this->delegation->refresh();

            return $this->caller->call($tool, $arguments);
        } catch (DelegationRevoked $revoked) {
            throw new ToolCallFailed('delegation_revoked: ' . $revoked->getMessage(), previous: $revoked);
        } catch (LocalToolCallFailed $failed) {
            throw new ToolCallFailed($failed->getMessage(), previous: $failed);
        }
    }
}
