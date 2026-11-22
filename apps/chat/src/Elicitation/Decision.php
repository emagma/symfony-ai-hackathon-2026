<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Elicitation;

/**
 * How a write confirmation ended, for the UI and the trace.
 */
final readonly class Decision
{
    public const MANUAL = 'manual';

    public const AUTO = 'auto';

    public const PRESET = 'preset';

    public function __construct(
        public string $action,
        public string $how,
        public int $waitedMs,
    ) {
    }

    public function outcome(): string
    {
        return $this->how . '_' . $this->action;
    }
}
