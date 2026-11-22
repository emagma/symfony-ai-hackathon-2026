<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle;

/**
 * How much attention an analysis deserves.
 */
enum Level: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
        };
    }
}
