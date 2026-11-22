<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Runner;

/**
 * The MCP Tools of the server, called from outside a `tools/call`: with the rights of whoever the worker
 * acts as at that moment.
 */
interface ToolInvokerInterface
{
    /**
     * Whether the tool exists and the current caller may call it.
     */
    public function allows(string $tool): bool;

    /**
     * The tools the current caller may call, by name.
     *
     * @return list<string>
     */
    public function tools(): array;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> what the tool answered
     *
     * @throws ToolCallFailed
     */
    public function invoke(string $tool, array $arguments): array;
}
