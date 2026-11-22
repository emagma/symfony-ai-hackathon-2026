<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Integration;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Entity\TaskAuthorization;
use Emagma\McpTasksBundle\Entity\TaskCall;
use Emagma\McpTasksBundle\Store\DoctrineTaskStore;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskStatus;
use PHPUnit\Framework\Attributes\CoversClass;

/** The entities and their store, mapped by attributes, on a real database. */
#[CoversClass(DoctrineTaskStore::class)]
#[CoversClass(Task::class)]
#[CoversClass(TaskCall::class)]
#[CoversClass(TaskAuthorization::class)]
final class DoctrineTaskStoreTest extends DatabaseTestCase
{
    public function testATaskIsStoredAndReadBack(): void
    {
        $store = $this->store();
        $store->create($this->task('t1', 'alice'));
        $this->em->clear();

        $task = $store->find('t1');

        self::assertInstanceOf(Task::class, $task);
        self::assertSame('alice', $task->getOwner());
        self::assertSame(TaskStatus::Draft, $task->getStatus());
        self::assertSame(['search_products'], $task->getManifest());
        self::assertSame(-1, $task->getClaimedStep());
        self::assertFalse($task->isStepInFlight());
        self::assertNull($store->find('nobody'));
    }

    public function testAStepIsClaimedOnceAndTheStatusMovesFromOneStateOnly(): void
    {
        $store = $this->store();
        $store->create($this->task('t1', 'alice'));

        self::assertTrue($store->transition('t1', TaskStatus::Draft, TaskStatus::Queued));
        self::assertFalse($store->transition('t1', TaskStatus::Draft, TaskStatus::Queued), 'someone got there first');
        self::assertTrue($store->claim('t1', 0));
        self::assertFalse($store->claim('t1', 0), 'a step is taken once');
        self::assertSame(TaskStatus::Queued, $store->status('t1'), 'a claim moves the step, not the status');
    }

    public function testTheCallsOfATaskComeBackMostRecentFirst(): void
    {
        $store = $this->store();
        $store->create($this->task('t1', 'alice'));
        $first = new TaskCall('t1', 0, 'search_products', ['page' => 1], new \DateTimeImmutable('2026-11-22 10:00:00'));
        $second = new TaskCall('t1', 0, 'search_products', ['page' => 2], new \DateTimeImmutable('2026-11-22 10:00:01'));
        $store->addCall($first);
        $store->addCall($second);
        $second->succeed('ok', 12);
        $store->save();
        $this->em->clear();

        $calls = $store->calls('t1');

        self::assertSame([['page' => 2], ['page' => 1]], array_map(static fn (TaskCall $call): array => $call->getArguments(), $calls));
        self::assertTrue($calls[0]->isOver());
        self::assertFalse($calls[1]->isOver());
    }

    public function testTheOfflineTokenOfAnOwnerIsOneRow(): void
    {
        $this->em->persist(new TaskAuthorization('alice', 'secret', new \DateTimeImmutable('2026-11-22')));
        $this->em->flush();
        $this->em->clear();

        $authorization = $this->em->find(TaskAuthorization::class, 'alice');

        self::assertInstanceOf(TaskAuthorization::class, $authorization);
        self::assertSame('secret', $authorization->getToken());
    }

    private function store(): TaskStoreInterface
    {
        return $this->service(TaskStoreInterface::class, 'test.store');
    }

    private function task(string $id, string $owner): Task
    {
        return new Task($id, $owner, 'Translate', 'recap', 'reading', ['search_products'], ['translate'], new \DateTimeImmutable('2026-11-22 09:00:00'));
    }
}
