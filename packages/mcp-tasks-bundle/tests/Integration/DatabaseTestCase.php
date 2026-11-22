<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Emagma\McpTasksBundle\Tests\Integration\Fixture\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A fresh in-memory schema per test.
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
        $this->createSchema();
    }

    protected function createSchema(): void
    {
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
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
