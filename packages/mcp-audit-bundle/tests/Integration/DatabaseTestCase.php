<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\SchemaTool;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Tests\Integration\Fixture\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A fresh in-memory schema per test. Foreign keys are switched on: SQLite ignores them by default,
 * and the purge relies on `ON DELETE SET NULL`.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $this->createSchema();
    }

    protected function createSchema(): void
    {
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @return EntityRepository<AuditEntry>
     */
    protected function entries(): EntityRepository
    {
        return $this->em->getRepository(AuditEntry::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    protected function service(string $type, ?string $id = null): object
    {
        $service = self::getContainer()->get($id ?? $type);
        self::assertInstanceOf($type, $service);

        return $service;
    }
}
