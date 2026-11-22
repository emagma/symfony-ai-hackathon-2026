<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Emagma\McpAuditBundle\AuditWriter;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\Caller\NullActorProvider;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Emagma\McpCoreBundle\Redaction\SensitiveKeysProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(AuditWriter::class)]
final class AuditWriterTest extends TestCase
{
    public function testItStampsTheActorReportedByTheHost(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (AuditEntry $e): bool => 'kevin@emagma.fr' === $e->getActor(),
        ));

        $this->writer($em, actor: 'kevin@emagma.fr')->record(
            tool: 'update_channel',
            targetType: 'channel',
            targetCode: 'WEB',
            beforeState: [],
            afterState: [],
            diff: [],
        );
    }

    public function testItWritesNoActorRatherThanAFabricatedOne(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (AuditEntry $e): bool => null === $e->getActor(),
        ));

        $this->writer($em)->record('update_channel', 'channel', 'WEB', [], [], []);
    }

    public function testAnEntryOfAnIrreversibleToolIsNotRevertible(): void
    {
        $cancel = new #[Permission(resource: 'order', action: Action::Write)] #[Irreversible] class {
        };
        $rename = new #[Permission(resource: 'order', action: Action::Write)] class {
        };
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (AuditEntry $entry) use (&$persisted): void {
            $persisted[] = $entry;
        });
        $writer = new AuditWriter($em, new SensitiveDataRedactor(), tools: new ToolClassMap(['cancel_order' => $cancel::class, 'rename_order' => $rename::class]));

        $writer->record('cancel_order', 'order', 'tok', ['state' => 'new'], ['state' => 'cancelled'], []);
        $writer->record('rename_order', 'order', 'tok', ['name' => 'a'], ['name' => 'b'], []);
        $writer->record('update_taxon', 'taxon', 'mugs', ['name' => 'a'], ['name' => 'b'], []);

        self::assertSame([false, true, true], array_map(static fn (AuditEntry $entry): bool => $entry->isRevertible(), $persisted), 'a name the server no longer exposes is left to the revert');
    }

    public function testAnEmailGivenAsTargetCodeIsNotStoredAsGiven(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (AuditEntry $e): bool => SensitiveDataRedactor::MARKER === $e->getTargetCode(),
        ));

        $this->writer($em)->record('upsert_customers', 'customer', 'jane@example.com', [], [], []);
    }

    public function testAFailedFlushDoesNotBubbleUpAsAFailedWrite(): void
    {
        $em = $this->failingEntityManager([]);

        $this->writer($em, logger: $this->createMock(LoggerInterface::class))
            ->record('delete_promotion', 'promotion', 'summer', [], [], []);

        $this->expectNotToPerformAssertions();
    }

    public function testAFailedPersistIsAlsoCaughtAndReported(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willThrowException(new \RuntimeException('mapping not registered'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('critical')
            ->with(self::stringContains('could not be persisted'), self::callback(
                static function (array $context): bool {
                    self::assertSame('update_promotion', $context['tool']);

                    return true;
                },
            ));

        $entry = $this->writer($em, actor: 'kevin@emagma.fr', logger: $logger)
            ->recordEntry('update_promotion', 'promotion', 'summer', [], [], []);

        self::assertSame('update_promotion', $entry->getTool());
    }

    public function testAFailedFlushEmitsTheEntryAtCriticalInstead(): void
    {
        $entry = new AuditEntry('refund_payment', 'payment', '7', [], [], ['state' => ['before' => 'completed', 'after' => 'refunded']], 'sess-1', null, 'kevin@emagma.fr');
        $em = $this->failingEntityManager([$entry]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('critical')
            ->with(
                self::stringContains('could not be persisted'),
                self::callback(static function (array $context): bool {
                    self::assertSame('refund_payment', $context['tool']);
                    self::assertSame('7', $context['targetCode']);
                    self::assertSame('kevin@emagma.fr', $context['actor']);
                    self::assertArrayHasKey('diff', $context);

                    return true;
                }),
            );

        $this->writer($em, logger: $logger)->record('refund_payment', 'payment', '7', [], [], []);
    }

    public function testItStillReportsWhenTheEntriesThemselvesCannotBeRecovered(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('deadlock'));
        // A closed EntityManager throws on getUnitOfWork() too.
        $em->method('getUnitOfWork')->willThrowException(new \RuntimeException('closed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')->with(self::stringContains('could not be recovered'));

        $this->writer($em, logger: $logger)->record('cancel_order', 'order', 'TOKEN', [], [], []);
    }

    public function testRedactionStillAppliesBeforePersistence(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(
            static function (AuditEntry $e): bool {
                self::assertSame(SensitiveDataRedactor::MARKER, $e->getBeforeState()['email']);
                self::assertSame(SensitiveDataRedactor::MARKER, $e->getBeforeState()['username']);

                return true;
            },
        ));

        $this->writer($em)->record(
            'update_administrator',
            'administrator',
            '3',
            ['email' => 'jean@shop.test', 'username' => 'jean.dupont', 'enabled' => true],
            [],
            [],
        );
    }

    /**
     * @param list<AuditEntry> $scheduled
     */
    private function failingEntityManager(array $scheduled): EntityManagerInterface
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn($scheduled);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('the database went away'));
        $em->method('getUnitOfWork')->willReturn($unitOfWork);

        return $em;
    }

    private function writer(
        EntityManagerInterface $em,
        ?string $actor = null,
        ?LoggerInterface $logger = null,
    ): AuditWriter {
        $provider = null === $actor
            ? new NullActorProvider()
            : new class($actor) implements ActorProviderInterface {
                public function __construct(private readonly string $actor)
                {
                }

                public function actor(): ?string
                {
                    return $this->actor;
                }
            };

        return new AuditWriter($em, new SensitiveDataRedactor(providers: [self::backendKeys()]), $provider, $logger);
    }

    private static function backendKeys(): SensitiveKeysProviderInterface
    {
        return new class() implements SensitiveKeysProviderInterface {
            public function personalKeys(): array
            {
                return ['firstName', 'lastName', 'company', 'street', 'postcode', 'city', 'phoneNumber', 'username', 'email', 'fullName', 'customerIp', 'notes'];
            }

            public function opaqueSubtrees(): array
            {
                return ['gatewayconfig' => ['config']];
            }
        };
    }
}
