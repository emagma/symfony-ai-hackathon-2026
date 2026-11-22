<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Instruction;

use Mcp\Server\Builder;

/**
 * The server's instructions: the texts of the tagged instructions, highest priority first, joined by a blank line.
 * Set on the server's builder when the server is built.
 *
 * @internal
 */
final readonly class McpInstructions
{
    /**
     * @param iterable<McpInstructionInterface> $instructions sorted by the container, highest priority first
     */
    public function __construct(
        private iterable $instructions,
    ) {
    }

    public function text(): ?string
    {
        $blocks = [];
        foreach ($this->instructions as $instruction) {
            $text = trim($instruction->text());
            if ('' !== $text) {
                $blocks[] = $text;
            }
        }

        return [] === $blocks ? null : implode("\n\n", $blocks);
    }

    public function configure(Builder $builder): void
    {
        $builder->setInstructions($this->text());
    }
}
