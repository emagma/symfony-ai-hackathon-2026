<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Sandbox;

/**
 * One line of JSON each way.
 */
interface SandboxSessionInterface
{
    /**
     * @param array<string, mixed> $line
     */
    public function send(array $line): void;

    /**
     * @return array<string, mixed>|null the next line the script wrote; null when it is gone without one
     *
     * @throws SandboxTimeout when the session lasted longer than allowed
     */
    public function receive(): ?array;

    /**
     * What the process wrote on stderr: a fatal error, a warning.
     */
    public function errorOutput(): string;

    public function close(): void;
}
