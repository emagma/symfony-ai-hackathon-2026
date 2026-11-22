<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Integration;

use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\AuditWriter;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Repository\AuditRepository;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpAuditBundle\Revert\NullRevertTarget;
use Emagma\McpAuditBundle\Tests\AuditTestTrait;
use Emagma\McpAuditBundle\Tool\GetMcpEdits;
use Emagma\McpAuditBundle\Tool\RevertMcpEdit;
use Emagma\McpAuditBundle\Tool\SearchMcpEdits;
use PHPUnit\Framework\Attributes\CoversClass;

/** The scopes on the real queries: `audit` reaches the caller's own entries, `audit_all` every entry. */
#[CoversClass(AuditScope::class)]
#[CoversClass(AuditRepository::class)]
#[CoversClass(SearchMcpEdits::class)]
#[CoversClass(GetMcpEdits::class)]
#[CoversClass(RevertMcpEdit::class)]
final class AuditScopeTest extends DatabaseTestCase
{
    use AuditTestTrait;

    public function testAReaderSeesTheirOwnEntriesTheirCountAndNoOneElses(): void
    {
        [$ada, $bob, $cli] = $this->writeEntries();

        $own = $this->createAuditScope(['mcp:core:audit:read'], 'kc-ada');
        $search = (new SearchMcpEdits($this->service(AuditRepositoryInterface::class), $own))();
        self::assertSame([$ada->getId()], array_map(static fn (object $item): ?int => $item->id ?? null, $search['items']));
        self::assertSame(1, $search['total'], 'the total counts what the caller may see');

        $got = (new GetMcpEdits($this->service(AuditRepositoryInterface::class), $own))(ids: [(int) $ada->getId(), (int) $bob->getId(), (int) $cli->getId()]);
        self::assertCount(1, $got['found']);
        self::assertSame([(int) $bob->getId(), (int) $cli->getId()], $got['missing'], 'another\'s entry is missing, like one that does not exist');

        $all = $this->createAuditScope(['mcp:core:audit:read', 'mcp:core:audit_all:read'], 'kc-ada');
        self::assertSame(3, (new SearchMcpEdits($this->service(AuditRepositoryInterface::class), $all))()['total'], 'every entry, the CLI\'s included');
    }

    public function testACallerWithoutIdentityReachesNoEntryButThroughAuditAll(): void
    {
        $this->writeEntries();

        $anonymous = $this->createAuditScope(['mcp:core:audit:write']);
        self::assertSame(0, (new SearchMcpEdits($this->service(AuditRepositoryInterface::class), $anonymous))()['total'], 'not the entries without actor');
    }

    public function testARevertReachesTheCallersOwnEntriesOrEveryEntryUnderAuditAllWrite(): void
    {
        [, $bob] = $this->writeEntries();
        $batchOfBob = (string) $bob->getBatchId();

        $reader = $this->revert($this->createAuditScope(['mcp:core:audit:write', 'mcp:core:audit_all:read'], 'kc-ada'));
        self::assertSame('not_found', $reader(id: (int) $bob->getId(), confirm: true)['reason']);
        self::assertSame('not_found', $reader(batchId: $batchOfBob, confirm: true)['reason']);

        // Reached, then refused for want of the recording tool's grant: the scope is passed.
        $any = $this->revert($this->createAuditScope(['mcp:core:audit_all:write'], 'kc-ada'));
        self::assertSame('forbidden', $any(id: (int) $bob->getId(), confirm: true)['reason']);
    }

    /**
     * @return array{AuditEntry, AuditEntry, AuditEntry} Ada's, Bob's (in a batch), the CLI's
     */
    private function writeEntries(): array
    {
        $ada = new AuditEntry('upsert_taxons', 'taxon', 'mugs', ['name' => 'a'], ['name' => 'b'], [], actor: 'kc-ada');
        $bob = new AuditEntry('upsert_taxons', 'taxon', 'cups', ['name' => 'a'], ['name' => 'b'], [], actor: 'kc-bob', batchId: 'batch-of-bob');
        $cli = new AuditEntry('upsert_taxons', 'taxon', 'pots', ['name' => 'a'], ['name' => 'b'], []);
        foreach ([$ada, $bob, $cli] as $entry) {
            $this->em->persist($entry);
        }
        $this->em->flush();

        return [$ada, $bob, $cli];
    }

    private function revert(AuditScope $scope): RevertMcpEdit
    {
        return new RevertMcpEdit(new NullRevertTarget(), $this->service(AuditRepositoryInterface::class), $this->service(AuditWriter::class), $scope);
    }
}
