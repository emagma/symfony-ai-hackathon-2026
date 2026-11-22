<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Integration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Emagma\McpAuditBundle\EmagmaMcpAuditBundle;
use Emagma\McpAuditBundle\Migrations\Version20261122000000;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** The bundle's migration against the mapping, on a fresh database and on one that already has the table. */
#[CoversClass(Version20261122000000::class)]
#[CoversClass(EmagmaMcpAuditBundle::class)]
final class MigrationTest extends DatabaseTestCase
{
    public function testOnAFreshDatabaseItCreatesTheSchemaOfTheMapping(): void
    {
        $this->migrate();

        self::assertSame([], $this->pendingSchemaChanges(), 'the migration and the mapping describe the same table');
    }

    public function testOnADatabaseThatHasTheTableItIsANoOpRecordedAsDone(): void
    {
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->migrate();

        self::assertSame([], $this->pendingSchemaChanges());
        self::assertStringContainsString('[OK] Already at the latest version', $this->migrate(), 'run without SQL, and recorded: not pending anymore');
    }

    /**
     * On the migration itself: Doctrine Migrations keeps the SQL a migration planned for the life of the process,
     * so an up then a down in one test would replay the up.
     */
    public function testRollingItBackKeepsTheTableAndItsEntries(): void
    {
        $migration = new Version20261122000000($this->em->getConnection(), new NullLogger());

        $migration->down(new Schema());

        self::assertSame([], $migration->getSql(), 'the audit is never dropped by a rollback');
    }

    /** The schema is the migration's business here. */
    protected function createSchema(): void
    {
    }

    private function migrate(): string
    {
        $command = (new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:migrate');
        $tester = new CommandTester($command);
        $tester->execute([], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /**
     * @return list<string>
     */
    private function pendingSchemaChanges(): array
    {
        return array_values(array_filter(
            (new SchemaTool($this->em))->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => !str_contains($sql, 'doctrine_migration_versions'),
        ));
    }
}
