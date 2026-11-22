<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Integration;

use Emagma\McpAuditBundle\Command\ForgetPersonalDataCommand;
use Emagma\McpAuditBundle\Command\PurgeAuditCommand;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Forget\PersonalDataEraser;
use Emagma\McpAuditBundle\Repository\AuditRepository;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Against a real (SQLite) schema: the one thing worth testing is the query. */
#[CoversClass(PurgeAuditCommand::class)]
#[CoversClass(ForgetPersonalDataCommand::class)]
#[CoversClass(PersonalDataEraser::class)]
#[CoversClass(AuditRepository::class)]
#[CoversClass(AuditEntry::class)]
final class AuditLifetimeTest extends DatabaseTestCase
{
    public function testThePurgeDeletesOnlyWhatIsOlderThanTheCutoffAndEmptiesAYoungerRevert(): void
    {
        $old = $this->entry('update_customers', 'customer', '12', createdAt: '-400 days');
        $revert = $this->entry('revert_mcp_edit', 'customer', '12', createdAt: '-10 days', reverts: $old);
        $recent = $this->entry('cancel_order', 'order', 'tok', createdAt: '-10 days');
        $this->em->flush();
        $this->em->clear();

        $tester = new CommandTester($this->service(Command::class, 'emagma_mcp_audit.command.purge'));

        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('1 audit entry', $tester->getDisplay());
        self::assertCount(3, $this->entries()->findAll(), 'a dry run deletes nothing');

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 audit entry', $tester->getDisplay());

        $this->em->clear();
        $ids = array_map(static fn (AuditEntry $e): ?int => $e->getId(), $this->entries()->findAll());
        self::assertEqualsCanonicalizing([$revert->getId(), $recent->getId()], $ids);
        $survivor = $this->entries()->find($revert->getId());
        self::assertNotNull($survivor);
        self::assertNull($survivor->getReverts(), 'ON DELETE SET NULL: the revert stays, its target is gone');
    }

    public function testOlderThanOverridesTheConfiguredRetention(): void
    {
        $this->entry('update_customers', 'customer', '12', createdAt: '-100 days');
        $this->entry('update_customers', 'customer', '13', createdAt: '-5 days');
        $this->em->flush();

        $tester = new CommandTester($this->service(Command::class, 'emagma_mcp_audit.command.purge'));
        $tester->execute(['--older-than' => 'P30D']);

        $this->em->clear();
        self::assertCount(1, $this->entries()->findAll());

        $tester->execute(['--older-than' => 'a month']);
        self::assertSame(2, $tester->getStatusCode());
    }

    public function testTheErasureScrubsTheCustomerAndWhateverPointsAtThemAndNothingElse(): void
    {
        $customer = $this->entry('upsert_customers', 'customer', '12', before: ['email' => $this->service(SensitiveDataRedactor::class)->marker(), 'group' => '/api/v2/admin/customer-groups/retail']);
        $order = $this->entry('cancel_order', 'order', 'tok-1', before: ['customer' => '/api/v2/admin/customers/12', 'state' => 'new', 'shippingAddress' => ['city' => 'Lyon']], after: ['customer' => '/api/v2/admin/customers/12', 'state' => 'cancelled']);
        $review = $this->entry('accept_product_review', 'product_review', '7', before: ['author' => '/api/v2/admin/customers/12', 'status' => 'new']);
        // The embedded shape another serialization group yields: the link is the `@id` of a nested object.
        $address = $this->entry('update_address', 'address', '31', before: ['customer' => ['@id' => '/api/v2/admin/customers/12', 'email' => $this->service(SensitiveDataRedactor::class)->marker()], 'city' => 'Lyon']);
        $other = $this->entry('cancel_order', 'order', 'tok-2', before: ['customer' => '/api/v2/admin/customers/120', 'state' => 'new']);
        $this->em->flush();
        $this->em->clear();

        $tester = new CommandTester($this->service(Command::class, 'emagma_mcp_audit.command.forget_personal_data'));

        $tester->execute(['type' => 'customer', 'id' => '12', '--dry-run' => true]);
        self::assertStringContainsString('4 entries would be forgotten, 0 already are', $tester->getDisplay());
        $this->em->clear();
        self::assertSame('new', $this->entries()->find($order->getId())?->getBeforeState()['state'] ?? null, 'a dry run changes nothing');

        $tester->execute(['type' => 'customer', 'id' => '12']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('4 entries forgotten for customer 12', $tester->getDisplay());

        $this->em->clear();
        foreach ([$customer, $order, $review, $address] as $forgotten) {
            $reloaded = $this->entries()->find($forgotten->getId());
            self::assertNotNull($reloaded, 'the row remains');
            self::assertSame($forgotten->getTargetCode(), $reloaded->getTargetCode(), 'what was touched is still on record');
            foreach ([$reloaded->getBeforeState(), $reloaded->getAfterState(), $reloaded->getDiff()] as $payload) {
                self::assertTrue([] === $payload || $this->service(SensitiveDataRedactor::class)->containsRedaction($payload));
                self::assertSame([], array_filter(self::leaves($payload), fn (mixed $leaf): bool => $this->service(SensitiveDataRedactor::class)->marker() !== $leaf), 'every value is the marker');
            }
        }
        self::assertSame(['customer' => '/api/v2/admin/customers/120', 'state' => 'new'], $this->entries()->find($other->getId())?->getBeforeState(), 'customer 120 is not customer 12');
    }

    public function testForgettingAnUnknownCustomerIsANoOp(): void
    {
        $tester = new CommandTester($this->service(Command::class, 'emagma_mcp_audit.command.forget_personal_data'));
        $tester->execute(['type' => 'customer', 'id' => '999']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No audit entry concerns customer 999', $tester->getDisplay());

        $tester->execute(['type' => 'order', 'id' => 'tok']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No audit entry concerns order tok', $tester->getDisplay());

        $tester->execute(['type' => 'customer', 'id' => ' ']);
        self::assertSame(2, $tester->getStatusCode(), 'a subject needs an id');
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function entry(string $tool, string $targetType, string $targetCode, array $before = ['x' => 1], array $after = ['x' => 2], string $createdAt = 'now', ?AuditEntry $reverts = null): AuditEntry
    {
        $entry = new AuditEntry($tool, $targetType, $targetCode, $before, $after, ['x' => ['before' => 1, 'after' => 2]], reverts: $reverts);
        if ('now' !== $createdAt) {
            $property = new \ReflectionProperty(AuditEntry::class, 'createdAt');
            $property->setValue($entry, new \DateTimeImmutable($createdAt));
        }
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<mixed>
     */
    private static function leaves(array $payload): array
    {
        $leaves = [];
        foreach ($payload as $value) {
            if (\is_array($value)) {
                $leaves = [...$leaves, ...self::leaves($value)];
            } else {
                $leaves[] = $value;
            }
        }

        return $leaves;
    }
}
