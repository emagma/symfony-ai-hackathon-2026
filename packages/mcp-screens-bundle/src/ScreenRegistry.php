<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle;

/**
 * The tagged screens, by name and sorted.
 */
final readonly class ScreenRegistry
{
    /** @var array<string, ScreenInterface> */
    private array $screens;

    /**
     * @param iterable<ScreenInterface> $screens
     */
    public function __construct(iterable $screens)
    {
        $byName = [];
        foreach ($screens as $screen) {
            $byName[$screen->name()] = $screen;
        }
        ksort($byName);
        $this->screens = $byName;
    }

    public function get(string $name): ?ScreenInterface
    {
        return $this->screens[$name] ?? null;
    }

    /**
     * @return array<string, ScreenInterface>
     */
    public function all(): array
    {
        return $this->screens;
    }
}
