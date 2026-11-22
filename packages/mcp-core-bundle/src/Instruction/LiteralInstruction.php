<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Instruction;

/**
 * A text written as it is: the `instructions` a host set on its server, kept as one instruction among the others.
 */
final readonly class LiteralInstruction implements McpInstructionInterface
{
    public function __construct(
        private string $text,
    ) {
    }

    public function text(): string
    {
        return $this->text;
    }
}
