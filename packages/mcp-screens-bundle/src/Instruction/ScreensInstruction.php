<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle\Instruction;

use Emagma\McpCoreBundle\Instruction\AsMcpInstruction;
use Emagma\McpCoreBundle\Instruction\McpInstructionInterface;
use Emagma\McpScreensBundle\ScreenRegistry;

/**
 * Lists the screens `open_screen` knows in the server's instructions: the model names the right one at once
 * instead of guessing a name and showing the user a refusal first.
 *
 * @internal
 */
#[AsMcpInstruction]
final readonly class ScreensInstruction implements McpInstructionInterface
{
    public function __construct(
        private ScreenRegistry $screens,
    ) {
    }

    public function text(): string
    {
        $screens = $this->screens->all();
        if ([] === $screens) {
            return '';
        }

        $lines = [
            '## Screens',
            'open_screen(screen, params) shows one of these screens to the user; prefer it to dictating values:',
        ];
        foreach ($screens as $name => $screen) {
            $lines[] = \sprintf('- %s: %s', $name, $screen->description());
        }

        return implode("\n", $lines);
    }
}
