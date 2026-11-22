<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Call;

use Emagma\McpCoreBundle\Journal\Irreversible;

/**
 * The class of each tool the server exposes, by name: filled at compile time from the server's
 * builder ({@see \Emagma\McpCoreBundle\DependencyInjection\Compiler\ToolClassMapPass}), so that it
 * answers in a command or a worker as in a request, whether the server was built or not.
 */
final class ToolClassMap
{
    /** @var array<string, bool> */
    private array $irreversible = [];

    /**
     * @param array<string, class-string> $classes tool name => handler class
     */
    public function __construct(
        private readonly array $classes = [],
    ) {
    }

    /**
     * @return class-string|null null for a name the server does not expose (anymore)
     */
    public function classOf(string $tool): ?string
    {
        return $this->classes[$tool] ?? null;
    }

    /**
     * An unknown name is not irreversible: nothing says so.
     */
    public function isIrreversible(string $tool): bool
    {
        $class = $this->classOf($tool);
        if (null === $class) {
            return false;
        }

        return $this->irreversible[$tool] ??= Irreversible::declaredBy($class);
    }
}
