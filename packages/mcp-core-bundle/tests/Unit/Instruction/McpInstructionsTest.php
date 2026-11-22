<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Instruction;

use Emagma\McpCoreBundle\Instruction\LiteralInstruction;
use Emagma\McpCoreBundle\Instruction\McpInstructions;
use Mcp\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpInstructions::class)]
#[CoversClass(LiteralInstruction::class)]
final class McpInstructionsTest extends TestCase
{
    public function testTheBlocksAreJoinedInTheirOrderAndAnEmptyOneIsSkipped(): void
    {
        $instructions = new McpInstructions([new LiteralInstruction("Conventions.\n"), new LiteralInstruction('  '), new LiteralInstruction('Guidelines.')]);

        self::assertSame("Conventions.\n\nGuidelines.", $instructions->text());
        self::assertNull((new McpInstructions([]))->text(), 'no instruction: none sent');
    }

    public function testTheBuilderReceivesThem(): void
    {
        $builder = Server::builder();

        (new McpInstructions([new LiteralInstruction('Conventions.')]))->configure($builder);

        $instructions = (new \ReflectionProperty($builder, 'instructions'))->getValue($builder);
        self::assertSame('Conventions.', $instructions);
    }
}
