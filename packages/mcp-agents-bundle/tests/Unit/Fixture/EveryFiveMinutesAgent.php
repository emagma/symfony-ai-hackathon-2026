<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit\Fixture;

use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;

/**
 * An agent that runs on its own, counting its runs.
 */
final class EveryFiveMinutesAgent implements ScheduledAgentInterface
{
    public int $runs = 0;

    public static function name(): string
    {
        return 'every_five_minutes';
    }

    public function every(): ?string
    {
        return '5 minutes';
    }

    public function run(): array
    {
        ++$this->runs;

        return ['runs' => $this->runs];
    }
}
