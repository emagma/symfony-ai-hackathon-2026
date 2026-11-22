<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Instruction;

/**
 * A block of the server's instructions, sent to the client when a conversation starts. Any bundle can bring one:
 * tag the service `emagma_mcp_core.instruction` with a priority, or put #[AsMcpInstruction] on its class.
 */
interface McpInstructionInterface
{
    /**
     * The block, in Markdown; '' adds nothing.
     */
    public function text(): string;
}
