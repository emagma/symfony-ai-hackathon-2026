<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Tests\Unit\Instruction;

use Emagma\McpGuidelinesBundle\Instruction\GuidelinesInstruction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuidelinesInstruction::class)]
final class GuidelinesInstructionTest extends TestCase
{
    public function testTheModelIsToldToAskForTheGuidelinesOfWhatItWrites(): void
    {
        $text = (new GuidelinesInstruction([
            'product_description' => ['description' => 'Product written content', 'guidelines' => ['company', 'catalog_content']],
            'customer_reply' => ['description' => 'Writing to a customer', 'guidelines' => ['company', 'customer_message']],
        ]))->text();

        self::assertStringContainsString('you MUST call get_guidelines(intents: [...])', $text);
        self::assertStringContainsString("- product_description: Product written content\n- customer_reply: Writing to a customer", $text);
        self::assertStringNotContainsString('catalog_content', $text, 'the intents, not the guidelines');
    }

    public function testWithoutIntentNothingIsSaid(): void
    {
        self::assertSame('', (new GuidelinesInstruction([]))->text());
    }
}
