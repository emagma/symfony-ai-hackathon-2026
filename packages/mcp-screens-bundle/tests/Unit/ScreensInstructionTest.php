<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle\Tests\Unit;

use Emagma\McpScreensBundle\Instruction\ScreensInstruction;
use Emagma\McpScreensBundle\ScreenRegistry;
use Emagma\McpScreensBundle\Tests\Unit\Fixture\NoteScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScreensInstruction::class)]
final class ScreensInstructionTest extends TestCase
{
    public function testItListsEveryScreenWithWhatItTakes(): void
    {
        $text = (new ScreensInstruction(new ScreenRegistry([new NoteScreen()])))->text();

        self::assertStringContainsString('## Screens', $text);
        self::assertStringContainsString('- note: One note. params: {id}.', $text);
    }

    public function testNoScreenNoInstruction(): void
    {
        self::assertSame('', (new ScreensInstruction(new ScreenRegistry([])))->text());
    }
}
