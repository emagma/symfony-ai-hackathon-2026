<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Instruction;

/**
 * Tags a McpInstructionInterface service `emagma_mcp_core.instruction`. The highest priority comes first: above 0 what
 * the server is (a backend's conventions), 0 the business, below 0 what must be read last.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsMcpInstruction
{
    public function __construct(
        public int $priority = 0,
    ) {
    }
}
