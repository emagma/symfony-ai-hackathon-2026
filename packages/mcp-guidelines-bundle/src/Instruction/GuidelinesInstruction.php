<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Instruction;

use Emagma\McpCoreBundle\Instruction\AsMcpInstruction;
use Emagma\McpCoreBundle\Instruction\McpInstructionInterface;

/**
 * Tells the model, when the conversation starts, which intents have guidelines and that it must ask for them
 * before writing. Generated from `emagma_mcp_guidelines.intents`; nothing without an intent.
 *
 * @internal
 */
#[AsMcpInstruction]
final readonly class GuidelinesInstruction implements McpInstructionInterface
{
    /**
     * @param array<string, array{description: string, guidelines: list<string>}> $intents
     */
    public function __construct(
        private array $intents,
    ) {
    }

    public function text(): string
    {
        if ([] === $this->intents) {
            return '';
        }

        $lines = [
            '## Writing for the business',
            'Before writing any text for the business, you MUST call get_guidelines(intents: [...]) with the intents of'
                . ' what you are about to write, and follow what it returns. Changing a status, a price, a stock or a code'
                . ' is not writing.',
        ];
        foreach ($this->intents as $intent => $definition) {
            $lines[] = \sprintf('- %s: %s', $intent, $definition['description']);
        }

        return implode("\n", $lines);
    }
}
