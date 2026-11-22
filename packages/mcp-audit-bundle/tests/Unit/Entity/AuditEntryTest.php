<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit\Entity;

use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEntry::class)]
final class AuditEntryTest extends TestCase
{
    private const string M = SensitiveDataRedactor::MARKER;

    public function testForgettingErasesEveryValueKeepsTheKeysAndLocksTheEntry(): void
    {
        $trace = new AuditEntry('forget_audit_personal_data', 'customer', '12', [], ['forgotten' => 1], [], locked: true);
        $entry = new AuditEntry('upsert_customers', 'customer', '12', ['firstName' => 'Ada', 'address' => ['city' => 'Lyon']], ['firstName' => 'Ada L.'], ['firstName' => ['before' => 'Ada', 'after' => 'Ada L.']]);

        $entry->forget(self::M, $trace);

        self::assertSame(['firstName' => self::M, 'address' => ['city' => self::M]], $entry->getBeforeState());
        self::assertSame(['firstName' => ['before' => self::M, 'after' => self::M]], $entry->getDiff());
        self::assertTrue($entry->isForgotten());
        self::assertSame($trace, $entry->getForgottenBy());
        self::assertSame('12', $entry->getTargetCode(), 'what was touched stays on record');
    }

    public function testAForgottenEntryIsForgottenOnceAndNeverMarkedReverted(): void
    {
        $entry = new AuditEntry('upsert_customers', 'customer', '12', ['firstName' => 'Ada'], [], []);
        $entry->forget(self::M);
        $at = $entry->getForgottenAt();

        $entry->forget('__ANOTHER_MARKER__', new AuditEntry('forget_audit_personal_data', 'customer', '12', [], [], []));

        self::assertSame($at, $entry->getForgottenAt());
        self::assertNull($entry->getForgottenBy(), 'forgetting again is a no-op');
        self::assertSame(['firstName' => self::M], $entry->getBeforeState());

        $this->expectException(\LogicException::class);
        $entry->setRevertedBy(new AuditEntry('revert_mcp_edit', 'customer', '12', [], [], []));
    }

    public function testTheTraceOfAnErasureIsBornLockedAndAnEntryRevertibleUnlessToldOtherwise(): void
    {
        $trace = new AuditEntry('forget_audit_personal_data', 'customer', '12', [], ['forgotten' => 3], [], revertible: false, locked: true);
        $entry = new AuditEntry('upsert_taxons', 'taxon', 'mugs', ['name' => 'a'], [], []);

        self::assertTrue($trace->isForgotten());
        self::assertFalse($trace->isRevertible());
        self::assertFalse($entry->isForgotten());
        self::assertTrue($entry->isRevertible());
    }
}
