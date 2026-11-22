<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Integration;

use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\EventListener\AuditEntryLockListener;
use Emagma\McpAuditBundle\Forget\PersonalDataEraser;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpAuditBundle\Tool\ForgetAuditPersonalData;
use PHPUnit\Framework\Attributes\CoversClass;

/** `forget_audit_personal_data` on a real schema: the erasure, its trace, the lock. */
#[CoversClass(ForgetAuditPersonalData::class)]
#[CoversClass(PersonalDataEraser::class)]
#[CoversClass(AuditEntryLockListener::class)]
#[CoversClass(AuditEntry::class)]
final class AuditErasureTest extends DatabaseTestCase
{
    public function testTheErasureIsTracedOnceCountsOnlyAndLocksWhatItForgot(): void
    {
        $customer = $this->entry('upsert_customers', 'customer', '12', ['firstName' => 'Ada']);
        $order = $this->entry('cancel_order', 'order', 'tok-1', ['customer' => '/api/v2/admin/customers/12', 'state' => 'new']);
        $other = $this->entry('upsert_customers', 'customer', '13', ['firstName' => 'Bob']);
        $this->em->flush();

        $result = $this->tool()(type: 'customer', id: '12', confirm: true);

        self::assertSame(['ok' => true, 'type' => 'customer', 'id' => '12', 'forgotten' => 2, 'ids' => [$customer->getId(), $order->getId()]], $result);
        $this->em->clear();

        $trace = $this->entries()->findOneBy(['tool' => 'forget_audit_personal_data']);
        self::assertNotNull($trace);
        self::assertSame(['customer', '12'], [$trace->getTargetType(), $trace->getTargetCode()]);
        self::assertSame(['forgotten' => 2], $trace->getAfterState(), 'counts only');
        self::assertTrue($trace->isForgotten(), 'the trace is born locked');
        self::assertFalse($trace->isRevertible());

        foreach ([$customer, $order] as $forgotten) {
            $reloaded = $this->entries()->find($forgotten->getId());
            self::assertNotNull($reloaded);
            self::assertTrue($reloaded->isForgotten());
            self::assertSame($trace->getId(), $reloaded->getForgottenBy()?->getId());
        }
        self::assertSame(['firstName' => 'Bob'], $this->entries()->find($other->getId())?->getBeforeState());

        // Forgotten entries match again, and are skipped: no new trace, nothing counted.
        self::assertSame(['ok' => true, 'type' => 'customer', 'id' => '12', 'forgotten' => 0, 'ids' => []], $this->tool()(type: 'customer', id: '12', confirm: true));
        self::assertCount(1, $this->entries()->findBy(['tool' => 'forget_audit_personal_data']));
    }

    public function testNothingConcerningTheSubjectIsNotFound(): void
    {
        self::assertSame(['ok' => false, 'reason' => 'not_found', 'type' => 'customer', 'id' => '999'], $this->tool()(type: 'customer', id: '999', confirm: true));
    }

    public function testAForgottenRowIsNeverUpdatedAgain(): void
    {
        $entry = $this->entry('upsert_customers', 'customer', '12', ['firstName' => 'Ada']);
        $this->em->flush();
        $this->tool()(type: 'customer', id: '12', confirm: true);
        $this->em->clear();

        $reloaded = $this->entries()->find($entry->getId());
        self::assertNotNull($reloaded);
        // What forget() and setRevertedBy() refuse in PHP, the listener refuses on the row.
        (new \ReflectionProperty(AuditEntry::class, 'beforeState'))->setValue($reloaded, ['firstName' => 'Ada']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('locked');
        $this->em->flush();
    }

    public function testAForgottenRowIsStillPurged(): void
    {
        $entry = $this->entry('upsert_customers', 'customer', '12', ['firstName' => 'Ada']);
        (new \ReflectionProperty(AuditEntry::class, 'createdAt'))->setValue($entry, new \DateTimeImmutable('-400 days'));
        $this->em->flush();
        $this->tool()(type: 'customer', id: '12', confirm: true);

        self::assertSame(1, $this->service(AuditRepositoryInterface::class)->purgeBefore(new \DateTimeImmutable('-1 year')), 'the retention deletes it, not the trace');
    }

    /**
     * @param array<string, mixed> $before
     */
    private function entry(string $tool, string $targetType, string $targetCode, array $before): AuditEntry
    {
        $entry = new AuditEntry($tool, $targetType, $targetCode, $before, $before, []);
        $this->em->persist($entry);

        return $entry;
    }

    private function tool(): ForgetAuditPersonalData
    {
        return new ForgetAuditPersonalData($this->service(PersonalDataEraser::class));
    }
}
