<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\AuditWriter;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Revert\NullRevertTarget;
use Emagma\McpAuditBundle\Tests\AuditTestTrait;
use Emagma\McpAuditBundle\Tool\RevertMcpEdit;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Exception\RevertRefusedException;
use Emagma\McpCoreBundle\Journal\RevertTargetInterface;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Emagma\McpCoreBundle\Testing\ElicitationTestTrait;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RevertMcpEdit::class)]
#[CoversClass(NullRevertTarget::class)]
final class RevertMcpEditTest extends TestCase
{
    use AuditTestTrait;
    use ElicitationTestTrait;

    public function testItWritesTheSnapshotBackAndRecordsTheRevert(): void
    {
        $target = $this->target(current: ['name' => 'New', 'price' => 12]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (AuditEntry $e): bool => 'revert_mcp_edit' === $e->getTool() && 'product' === $e->getTargetType() && 'MUG' === $e->getTargetCode(),
        ));
        // record() flushes once; markReverted() flushes again.
        $em->expects(self::exactly(2))->method('flush');

        $result = $this->tool($this->entry(), $target, $em)(id: 1, confirm: true);

        self::assertTrue($result['ok']);
        self::assertTrue($result['reverted']);
        self::assertSame('product', $result['targetType']);
        self::assertSame('MUG', $result['targetCode']);
        self::assertSame(['name' => ['before' => 'New', 'after' => 'Old']], $result['diff']);
        self::assertSame([['shelf/MUG', ['address' => 'shelf/MUG', 'name' => 'Old', 'price' => 12]]], $target->written);
    }

    public function testAnUnknownEntryIsNotFound(): void
    {
        $result = (new RevertMcpEdit($this->target(), $this->createAuditRepository(), $this->audit(), $this->createAuditScope()))(id: 404, confirm: true);

        self::assertSame(['ok' => false, 'reverted' => false, 'reason' => 'not_found', 'id' => 404], $result);
    }

    public function testAnotherCallersEntryIsNotFoundUnlessEveryEntryIsGranted(): void
    {
        $entry = new AuditEntry('update_product', 'product', 'MUG', ['address' => 'shelf/MUG', 'name' => 'Old'], [], [], actor: 'kc-bob');
        $target = $this->target(current: ['name' => 'New']);

        $own = $this->tool($entry, $target, scope: $this->createAuditScope(['mcp:core:audit:write', 'mcp:core:audit_all:read'], 'kc-ada'))(id: 1, confirm: true);
        self::assertSame(['ok' => false, 'reverted' => false, 'reason' => 'not_found', 'id' => 1], $own, 'reading every entry is not reverting every entry');

        $any = $this->tool($entry, $target, scope: $this->createAuditScope(['mcp:core:audit_all:write'], 'kc-ada'))(id: 1, confirm: true);
        self::assertSame('forbidden', $any['reason'], 'found, then refused: the grant of update_product is missing');
    }

    public function testARevertNeedsTheGrantOfTheToolThatMadeTheEdit(): void
    {
        $tool = new #[Permission(resource: 'product', action: Action::Write)] class {
        };
        $entry = new AuditEntry('update_product', 'product', 'MUG', ['address' => 'shelf/MUG', 'name' => 'Old'], [], [], actor: 'kc-ada');
        $tools = ['update_product' => $tool::class];
        $domains = ['class@anonymous' => 'sylius'];

        $readOnly = $this->target(readFailure: new RevertRefusedException(false, ['reason' => 'must_not_be_read']));
        $refused = $this->tool($entry, $readOnly, scope: $this->createAuditScope(['mcp:core:audit:write', 'mcp:sylius:product:read'], 'kc-ada', $tools, $domains))(id: 1, confirm: true);
        self::assertSame(['forbidden', 'update_product'], [$refused['reason'], $refused['tool']]);

        $allowed = $this->tool($entry, $this->target(current: ['name' => 'New']), scope: $this->createAuditScope(['mcp:core:audit:write', 'mcp:sylius:product:write'], 'kc-ada', $tools, $domains))(id: 1, confirm: true);
        self::assertTrue($allowed['reverted']);
    }

    public function testAnIrreversibleOrForgottenEntryIsRefusedBeforeAnythingIsRead(): void
    {
        $neverRead = $this->target(readFailure: new RevertRefusedException(false, ['reason' => 'must_not_be_read']));
        $cancelled = new AuditEntry('cancel_order', 'order', 'tok', ['address' => 'shelf/tok', 'state' => 'new'], ['state' => 'cancelled'], [], revertible: false);
        $forgotten = $this->entry();
        $forgotten->forget();

        self::assertSame('nothing_to_restore', $this->tool($cancelled, $neverRead)(id: 1, confirm: true)['reason']);
        self::assertSame('redacted_snapshot', $this->tool($forgotten, $neverRead)(id: 1, confirm: true)['reason']);
    }

    public function testIdAndBatchIdAreExclusive(): void
    {
        $tool = $this->tool($this->entry(), $this->target());

        self::assertSame('id_or_batch_id', $tool()['reason']);
        self::assertSame('id_or_batch_id', $tool(id: 1, batchId: 'b')['reason']);
    }

    public function testACreationHasNothingToRestore(): void
    {
        $created = new AuditEntry('create_product', 'product', 'MUG', [], ['address' => 'shelf/MUG'], []);

        $result = $this->tool($created, $this->target())(id: 1, confirm: true);

        self::assertSame('nothing_to_restore', $result['reason']);
    }

    public function testARedactedSnapshotIsNotReplayed(): void
    {
        $target = $this->target();
        $entry = new AuditEntry('update_customer', 'customer', '12', ['address' => 'shelf/12', 'email' => SensitiveDataRedactor::MARKER], [], []);

        $result = $this->tool($entry, $target)(id: 1, confirm: true);

        self::assertSame('redacted_snapshot', $result['reason']);
        self::assertSame([], $target->written);
    }

    public function testASnapshotTheBackendCannotLocateIsUnrevertable(): void
    {
        $entry = new AuditEntry('update_product', 'product', 'MUG', ['name' => 'Old'], [], []);

        self::assertSame('unrevertable_snapshot', $this->tool($entry, $this->target())(id: 1, confirm: true)['reason']);
    }

    public function testWithoutABackendEverySnapshotIsUnrevertable(): void
    {
        self::assertSame('unrevertable_snapshot', $this->tool($this->entry(), new NullRevertTarget())(id: 1, confirm: true)['reason']);
    }

    public function testAResourceThatIsGoneIsNamedAsSuch(): void
    {
        $target = $this->target(readFailure: new RevertRefusedException(true, ['reason' => 'not_found']));

        $result = $this->tool($this->entry(), $target)(id: 1, confirm: true);

        self::assertSame('target_not_found', $result['reason']);
        self::assertSame('MUG', $result['targetCode']);
    }

    public function testAnotherRefusalOfTheBackendIsPassedOn(): void
    {
        $read = $this->tool($this->entry(), $this->target(readFailure: new RevertRefusedException(false, ['reason' => 'upstream_error', 'status' => 500])))(id: 1, confirm: true);
        self::assertSame(['ok' => false, 'reverted' => false, 'id' => 1, 'reason' => 'upstream_error', 'status' => 500], array_intersect_key($read, ['ok' => 1, 'reverted' => 1, 'id' => 1, 'reason' => 1, 'status' => 1]));

        $write = $this->tool($this->entry(), $this->target(current: ['name' => 'New'], writeFailure: new RevertRefusedException(false, ['reason' => 'validation_error', 'errors' => [['property' => 'name']]])))(id: 1, confirm: true);
        self::assertSame(['ok' => false, 'reverted' => false, 'id' => 1, 'reason' => 'validation_error', 'errors' => [['property' => 'name']]], $write);
    }

    public function testAResourceAlreadyAtItsSnapshotIsReportedAsSuch(): void
    {
        $target = $this->target(current: ['name' => 'Old', 'price' => 12]);

        self::assertSame('already_at_target_state', $this->tool($this->entry(), $target)(id: 1, confirm: true)['reason']);
        self::assertSame([], $target->written);
    }

    public function testAnIncompleteRevertIsReportedAndNotMarkedAsReverted(): void
    {
        $entry = $this->entry();
        $target = $this->target(current: ['name' => 'New', 'price' => 12], writes: static fn (array $snapshot): array => ['name' => 'New'] + $snapshot);

        $result = $this->tool($entry, $target)(id: 1, confirm: true);

        self::assertSame('revert_incomplete', $result['reason']);
        self::assertSame(['name'], array_keys($result['notApplied']));
        self::assertNull($entry->getRevertedBy(), 'a later attempt must not be blocked by a claim that it was undone');
    }

    public function testWithoutConfirmationNothingIsWritten(): void
    {
        $target = $this->target(current: ['name' => 'New', 'price' => 12]);

        $result = $this->tool($this->entry(), $target)(id: 1);

        self::assertSame('elicitation_unavailable_preview_only', $result['reason']);
        self::assertSame(['name' => ['before' => 'New', 'after' => 'Old']], $result['diff']);
        self::assertSame([], $target->written);
    }

    public function testAHumanWhoDeclinesStopsTheRevert(): void
    {
        $target = $this->target(current: ['name' => 'New']);
        $tool = $this->tool($this->entry(), $target);

        $result = $this->runWithElicitation(fn (): array => $tool(id: 1, context: $this->elicitingContext()), ElicitAction::Decline);

        self::assertSame('declined', $result['reason']);
        self::assertSame([], $target->written);
    }

    public function testAHumanWhoAcceptsLetsItThrough(): void
    {
        $target = $this->target(current: ['name' => 'New']);
        $tool = $this->tool($this->entry(), $target);

        $result = $this->runWithElicitation(fn (): array => $tool(id: 1, context: $this->elicitingContext()), ElicitAction::Accept, ['confirm' => true]);

        self::assertTrue($result['reverted']);
        self::assertCount(1, $target->written);
    }

    private function entry(): AuditEntry
    {
        return new AuditEntry(
            tool: 'update_product',
            targetType: 'product',
            targetCode: 'MUG',
            beforeState: ['address' => 'shelf/MUG', 'name' => 'Old', 'price' => 12],
            afterState: ['address' => 'shelf/MUG', 'name' => 'New', 'price' => 12],
            diff: ['name' => ['before' => 'Old', 'after' => 'New']],
        );
    }

    private function tool(AuditEntry $entry, RevertTargetInterface $target, ?EntityManagerInterface $em = null, ?AuditScope $scope = null): RevertMcpEdit
    {
        return new RevertMcpEdit($target, $this->createAuditRepository(findResult: $entry), $this->audit($em), $scope ?? $this->createAuditScope());
    }

    private function audit(?EntityManagerInterface $em = null): AuditWriter
    {
        return new AuditWriter($em ?? $this->createStub(EntityManagerInterface::class), new SensitiveDataRedactor());
    }

    /**
     * @param array<string, mixed>|null                                  $current what it holds now (the address aside)
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $writes  what it holds after a write, the snapshot by default
     */
    private function target(?array $current = null, ?RevertRefusedException $readFailure = null, ?RevertRefusedException $writeFailure = null, ?\Closure $writes = null): RevertTargetInterface
    {
        return new class($current, $readFailure, $writeFailure, $writes) implements RevertTargetInterface {
            /** @var list<array{string, array<string, mixed>}> */
            public array $written = [];

            /**
             * @param array<string, mixed>|null $current
             */
            public function __construct(
                private readonly ?array $current,
                private readonly ?RevertRefusedException $readFailure,
                private readonly ?RevertRefusedException $writeFailure,
                private readonly ?\Closure $writes,
            ) {
            }

            public function locate(array $snapshot): ?string
            {
                return \is_string($snapshot['address'] ?? null) ? $snapshot['address'] : null;
            }

            public function read(string $address): array
            {
                if (null !== $this->readFailure) {
                    throw $this->readFailure;
                }

                return ['address' => $address] + ($this->current ?? []);
            }

            public function diff(array $current, array $snapshot): array
            {
                $diff = [];
                foreach ($snapshot as $field => $value) {
                    if (($current[$field] ?? null) !== $value) {
                        $diff[$field] = ['before' => $current[$field] ?? null, 'after' => $value];
                    }
                }

                return $diff;
            }

            public function readable(mixed $value): mixed
            {
                return $value;
            }

            public function write(string $address, array $snapshot): array
            {
                if (null !== $this->writeFailure) {
                    throw $this->writeFailure;
                }
                $this->written[] = [$address, $snapshot];

                /** @var array<string, mixed> $held */
                $held = null === $this->writes ? $snapshot : ($this->writes)($snapshot);

                return $held;
            }
        };
    }
}
