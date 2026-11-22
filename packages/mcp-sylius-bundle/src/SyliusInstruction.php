<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle;

use Emagma\McpCoreBundle\Instruction\McpInstructionInterface;

/**
 * The conventions every Sylius tool shares, first in the server's instructions: what the server is.
 *
 * @internal
 */
final readonly class SyliusInstruction implements McpInstructionInterface
{
    /**
     * @param bool $audit whether the audit's tools are there to undo a write
     */
    public function __construct(
        private string $defaultLocale,
        private bool $audit,
    ) {
    }

    public function text(): string
    {
        return ServerInstructions::text($this->defaultLocale, $this->audit);
    }
}
