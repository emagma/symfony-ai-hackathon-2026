<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit\Tool;

use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Tests\AuditTestTrait;
use Emagma\McpAuditBundle\Tool\SearchMcpEdits;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchMcpEdits::class)]
final class ListMcpEditsTest extends TestCase
{
    use AuditTestTrait;

    /**
     * @return array<string, mixed>
     */
    private function call(object $tool, mixed ...$arguments): array
    {
        self::assertIsCallable($tool);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($tool(...$arguments), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchMcpEdits::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_mcp_edits', $attributes[0]->newInstance()->name);
    }

    public function testItReturnsLeanShapedItemsWithoutBeforeAfterOrDiff(): void
    {
        $entry = $this->makeEntry('update_taxon', 'taxon', 'dresses');
        $repo = $this->createAuditRepository(paginateItems: [$entry], paginateTotal: 1);

        $result = $this->call(new SearchMcpEdits($repo, $this->createAuditScope()));

        self::assertCount(1, $result['items']);
        self::assertSame('update_taxon', $result['items'][0]['tool']);
        self::assertArrayNotHasKey('before', $result['items'][0]);
        self::assertArrayNotHasKey('after', $result['items'][0]);
        self::assertArrayNotHasKey('diff', $result['items'][0]);
    }

    public function testItReturnsHasMoreTrueWhenTotalExceedsPage(): void
    {
        $items = array_map(
            fn (int $n): AuditEntry => $this->makeEntry('t', 'taxon', (string) $n),
            range(1, 30),
        );
        $repo = $this->createAuditRepository(paginateItems: $items, paginateTotal: 87);

        $result = $this->call(new SearchMcpEdits($repo, $this->createAuditScope()), page: 1, itemsPerPage: 30);

        self::assertTrue($result['hasMore']);
        self::assertSame(87, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertCount(30, $result['items']);
    }

    public function testItReturnsHasMoreFalseOnLastPage(): void
    {
        $repo = $this->createAuditRepository(paginateItems: [], paginateTotal: 5);

        $result = $this->call(new SearchMcpEdits($repo, $this->createAuditScope()), page: 1, itemsPerPage: 30);

        self::assertFalse($result['hasMore']);
        self::assertSame(5, $result['total']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $repo = $this->createAuditRepository(paginateItems: [], paginateTotal: 0);

        $result = $this->call(new SearchMcpEdits($repo, $this->createAuditScope()), itemsPerPage: 999);

        self::assertSame(0, $result['total']);
        self::assertFalse($result['hasMore']);
    }

    public function testItForcesPageToAtLeastOne(): void
    {
        $repo = $this->createAuditRepository(paginateItems: [], paginateTotal: 0);

        $result = $this->call(new SearchMcpEdits($repo, $this->createAuditScope()), page: -5);

        self::assertSame(1, $result['page']);
    }

    public function testItSilentlyDropsInvalidSinceDateAndStillReturns(): void
    {
        $repo = $this->createAuditRepository(paginateItems: [], paginateTotal: 0);

        $result = $this->call(new SearchMcpEdits($repo, $this->createAuditScope()), since: 'not-a-date');

        self::assertSame(0, $result['total']);
    }

    public function testItShapesRevertedFlagCorrectly(): void
    {
        $revertEntry = $this->makeEntry('revert_mcp_edit', 'taxon', 'dresses');
        $original = $this->makeEntry('update_taxon', 'taxon', 'dresses');
        $original->setRevertedBy($revertEntry);

        $repo = $this->createAuditRepository(paginateItems: [$original], paginateTotal: 1);

        $result = $this->call(new SearchMcpEdits($repo, $this->createAuditScope()));

        self::assertTrue($result['items'][0]['reverted']);
    }

    private function makeEntry(string $tool, string $targetType, string $targetCode): AuditEntry
    {
        return new AuditEntry(
            tool: $tool,
            targetType: $targetType,
            targetCode: $targetCode,
            beforeState: [],
            afterState: [],
            diff: [],
        );
    }
}
