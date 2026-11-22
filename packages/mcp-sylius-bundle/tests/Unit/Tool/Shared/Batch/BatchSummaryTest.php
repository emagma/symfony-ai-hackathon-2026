<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Shared\Batch;

use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchSummary::class)]
final class BatchSummaryTest extends TestCase
{
    private const array CREATES = ['new' => ['code' => ['before' => null, 'after' => 'new']]];

    private const array UPDATES = ['old' => ['name' => ['before' => 'A', 'after' => 'B']]];

    public function testWithoutADetailTheSummaryNamesTheFieldsOnly(): void
    {
        self::assertSame(
            "Batch on things: 1 creation(s), 1 update(s)\n\n1 creation(s):\n  + new\n\n1 update(s):\n  ~ old (name)",
            BatchSummary::upsert('things', self::CREATES, self::UPDATES, []),
        );
    }

    public function testADetailAddsIndentedLinesUnderEachItem(): void
    {
        $summary = BatchSummary::upsert(
            'things',
            self::CREATES,
            self::UPDATES,
            ['bad' => ['reason' => 'channel_not_found']],
            static fn (string $code, array $diff): array => [$code . ': ' . implode(', ', array_keys($diff))],
        );

        self::assertSame(
            "Batch on things: 1 creation(s), 1 update(s)\n\n1 creation(s):\n  + new\n      new: code\n\n1 update(s):\n  ~ old (name)\n      old: name\n\n\nNot written: channel_not_found ×1",
            $summary,
        );
    }
}
