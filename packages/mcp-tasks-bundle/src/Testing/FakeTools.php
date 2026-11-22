<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Testing;

use Emagma\McpTasksBundle\Runner\ToolCallFailed;
use Emagma\McpTasksBundle\Runner\ToolInvokerInterface;

/**
 * The tools of a shop that only exists in memory.
 *
 * @internal
 */
final class FakeTools implements ToolInvokerInterface
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $received = [];

    /** @var list<string> tools the caller may not call any more */
    public array $forbidden = [];

    /**
     * @param array<string, \Closure(array<string, mixed>): array<string, mixed>> $tools
     */
    public function __construct(
        private array $tools,
    ) {
    }

    public function allows(string $tool): bool
    {
        return isset($this->tools[$tool]) && !\in_array($tool, $this->forbidden, true);
    }

    public function tools(): array
    {
        return array_values(array_diff(array_map(strval(...), array_keys($this->tools)), $this->forbidden));
    }

    public function invoke(string $tool, array $arguments): array
    {
        if (!$this->allows($tool)) {
            throw new ToolCallFailed(\sprintf('Unknown tool "%s".', $tool));
        }
        $this->received[] = [$tool, $arguments];

        return ($this->tools[$tool])($arguments);
    }

    /**
     * @return list<string>
     */
    public function called(): array
    {
        return array_column($this->received, 0);
    }
}
