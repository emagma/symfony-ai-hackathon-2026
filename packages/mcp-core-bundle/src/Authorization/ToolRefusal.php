<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * Why a gate refuses a tool: the answer a call gets instead of running it, and the notice logged with the tool's name.
 */
final readonly class ToolRefusal
{
    /**
     * `$logMessage` is logged at notice level when the tool is called anyway, with `$logContext` and the tool's name.
     *
     * @param array<string, mixed> $answer     what a call to the tool returns instead of running
     * @param array<string, mixed> $logContext
     */
    public function __construct(
        public array $answer,
        public string $logMessage,
        public array $logContext = [],
    ) {
    }
}
