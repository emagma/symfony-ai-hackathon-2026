<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Runner;

/**
 * The end of one step: done, or failed with the reason the task is suspended.
 */
final readonly class StepResult
{
    private function __construct(
        public bool $ok,
        public ?string $error = null,
    ) {
    }

    public static function done(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
