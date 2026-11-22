<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit\Fixture;

use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;

/**
 * An agent that only runs by hand, counting its runs.
 */
final class ByHandAgent implements ScheduledAgentInterface
{
    public int $runs = 0;

    public static function name(): string
    {
        return 'by_hand';
    }

    public function every(): ?string
    {
        return null;
    }

    public function run(): array
    {
        ++$this->runs;

        return ['runs' => $this->runs];
    }
}
