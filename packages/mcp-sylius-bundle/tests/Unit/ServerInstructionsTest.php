<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit;

use Emagma\McpSyliusBundle\ServerInstructions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerInstructions::class)]
final class ServerInstructionsTest extends TestCase
{
    public function testTheDefaultLocaleIsNamed(): void
    {
        self::assertStringContainsString('the `locale` argument, default `fr_FR`,', ServerInstructions::text('fr_FR', true));
    }

    public function testWithoutTheAuditNoToolOfItsIsPromised(): void
    {
        $with = ServerInstructions::text('en_US', true);
        $without = ServerInstructions::text('en_US', false);

        self::assertStringContainsString('revert_mcp_edit', $with);
        self::assertStringContainsString('search_mcp_edits(batchId=)', $with);
        self::assertStringNotContainsString('mcp_edit', $without);
        self::assertStringContainsString('{ok, action: created|updated|unchanged|deleted|failed, diff|reason|errors}`.', $without);
        self::assertSame(substr_count($with, "\n- ") - 1, substr_count($without, "\n- "));
    }
}
