<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Functional\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Foreign keys are switched on: SQLite ignores them by default, and a purge test checks `ON DELETE SET NULL`. */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    /** @var array{server: mixed, env: mixed} what `.env.test` had set, put back on tearDown */
    private array $previousDatabaseUrl;

    protected function setUp(): void
    {
        $this->previousDatabaseUrl = ['server' => $_SERVER['DATABASE_URL'] ?? null, 'env' => $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        foreach (['server' => &$_SERVER, 'env' => &$_ENV] as $key => &$bag) {
            if (null === $this->previousDatabaseUrl[$key]) {
                unset($bag['DATABASE_URL']);
            } else {
                $bag['DATABASE_URL'] = $this->previousDatabaseUrl[$key];
            }
        }
        unset($bag);
        parent::tearDown();
    }
}
