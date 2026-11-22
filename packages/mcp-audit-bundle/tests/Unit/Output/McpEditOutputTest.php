<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit\Output;

use Emagma\McpAuditBundle\DiffSummarizer;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Output\McpEditOutput;
use Emagma\McpAuditBundle\Output\McpEditSummaryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpEditOutput::class)]
final class McpEditOutputTest extends TestCase
{
    public function testTheSummaryContainsRequiredFields(): void
    {
        $entry = $this->makeEntry(tool: 'create_taxon', targetType: 'taxon', targetCode: 'dresses');

        $shaped = McpEditSummaryOutput::fromEntry($entry);

        self::assertSame('create_taxon', $shaped->tool);
        self::assertSame('taxon', $shaped->targetType);
        self::assertSame('dresses', $shaped->targetCode);
        self::assertFalse($shaped->reverted);
        self::assertNull($shaped->revertsId);
        self::assertNotSame('', $shaped->createdAt);
        self::assertNotSame('', $shaped->summary);
    }

    public function testTheSummaryDoesNotIncludeBeforeAfterOrDiff(): void
    {
        $entry = $this->makeEntry();

        $shaped = McpEditSummaryOutput::fromEntry($entry);

        $keys = array_keys(get_object_vars($shaped));
        self::assertNotContains('before', $keys);
        self::assertNotContains('after', $keys);
        self::assertNotContains('diff', $keys);
        self::assertNotContains('sessionId', $keys);
    }

    public function testTheFullEntryIncludesAllFields(): void
    {
        $before = ['code' => 'old'];
        $after = ['code' => 'new'];
        $diff = ['code' => ['before' => 'old', 'after' => 'new']];
        $entry = $this->makeEntry(
            tool: 'update_taxon',
            beforeState: $before,
            afterState: $after,
            diff: $diff,
            sessionId: 'sess-123',
        );

        $shaped = McpEditOutput::fromEntry($entry);

        self::assertSame($before, $shaped->before);
        self::assertSame($after, $shaped->after);
        self::assertSame($diff, $shaped->diff);
        self::assertSame('sess-123', $shaped->sessionId);
        self::assertSame('update_taxon', $shaped->tool);
    }

    public function testTheFullEntryFlagsRevertedEntry(): void
    {
        $revertEntry = $this->makeEntry();
        $original = $this->makeEntry();
        $original->setRevertedBy($revertEntry);

        $shaped = McpEditOutput::fromEntry($original);

        self::assertTrue($shaped->reverted);
    }

    public function testSummarizeBuildsDiffString(): void
    {
        $diff = [
            'name' => ['before' => 'Old', 'after' => 'New'],
            'enabled' => ['before' => true, 'after' => false],
        ];

        $summary = DiffSummarizer::summarize($diff);

        self::assertStringContainsString('name:', $summary);
        self::assertStringContainsString('"Old"', $summary);
        self::assertStringContainsString('"New"', $summary);
        self::assertStringContainsString('enabled:', $summary);
        self::assertStringContainsString('true', $summary);
        self::assertStringContainsString('false', $summary);
    }

    public function testSummarizeReturnsNoDiffPlaceholderOnEmptyDiff(): void
    {
        self::assertSame('(no diff)', DiffSummarizer::summarize([]));
    }

    public function testSummarizeHandlesArrayValues(): void
    {
        $diff = ['choices' => ['before' => [], 'after' => ['a', 'b']]];

        $summary = DiffSummarizer::summarize($diff);

        self::assertStringContainsString('choices:', $summary);
        self::assertStringContainsString('"a"', $summary);
    }

    public function testSummarizeTruncatesLongStrings(): void
    {
        $long = str_repeat('x', 80);
        $diff = ['description' => ['before' => null, 'after' => $long]];

        $summary = DiffSummarizer::summarize($diff);

        self::assertStringContainsString('...', $summary);
    }

    public function testCreatedAtIsFormattedAsAtomString(): void
    {
        $entry = $this->makeEntry();

        $shaped = McpEditSummaryOutput::fromEntry($entry);

        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $shaped->createdAt);
        self::assertInstanceOf(\DateTimeImmutable::class, $dt);
    }

    /** @param array<string, mixed> $beforeState @param array<string, mixed> $afterState @param array<string, mixed> $diff */
    private function makeEntry(
        string $tool = 'create_taxon',
        string $targetType = 'taxon',
        string $targetCode = 'test',
        array $beforeState = [],
        array $afterState = [],
        array $diff = [],
        ?string $sessionId = null,
    ): AuditEntry {
        return new AuditEntry(
            tool: $tool,
            targetType: $targetType,
            targetCode: $targetCode,
            beforeState: $beforeState,
            afterState: $afterState,
            diff: $diff,
            sessionId: $sessionId,
        );
    }
}
