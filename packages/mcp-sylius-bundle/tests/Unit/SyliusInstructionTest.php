<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit;

use Emagma\McpSyliusBundle\SyliusInstruction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyliusInstruction::class)]
final class SyliusInstructionTest extends TestCase
{
    public function testTheConventionsComeFirstInTheServersInstructions(): void
    {
        self::assertStringContainsString('default `fr_FR`', (new SyliusInstruction('fr_FR', false))->text());
        self::assertStringNotContainsString('revert_mcp_edit', (new SyliusInstruction('fr_FR', false))->text(), 'no audit bundle');
        self::assertStringContainsString('never call the write again on your own', (new SyliusInstruction('fr_FR', false))->text(), 'a decline is final');
    }
}
