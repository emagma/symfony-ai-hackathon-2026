<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Store;

use Doctrine\ORM\EntityManagerInterface;
use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Entity\TaskCall;
use Emagma\McpTasksBundle\TaskStatus;

/**
 * The tasks and their calls in the server's database.
 *
 * @internal
 */
final readonly class DoctrineTaskStore implements TaskStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(Task $task): void
    {
        $this->entityManager->persist($task);
        $this->entityManager->flush();
    }

    public function find(string $id): ?Task
    {
        $task = $this->entityManager->find(Task::class, $id);
        if (null !== $task) {
            // The worker or a cancellation may have moved it on since this process loaded it.
            $this->entityManager->refresh($task);
        }

        return $task;
    }

    public function findByOwner(?string $owner, ?TaskStatus $status = null, int $limit = 50): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('t')->from(Task::class, 't')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit);
        null === $owner ? $builder->andWhere('t.owner IS NULL') : $builder->andWhere('t.owner = :owner')->setParameter('owner', $owner);
        if (null !== $status) {
            $builder->andWhere('t.status = :status')->setParameter('status', $status->value);
        }

        /** @var list<Task> $tasks */
        $tasks = $builder->getQuery()->getResult();

        return $tasks;
    }

    public function findByStatus(TaskStatus $status): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager->createQueryBuilder()
            ->select('t')->from(Task::class, 't')
            ->where('t.status = :status')->setParameter('status', $status->value)
            ->getQuery()->getResult();

        return $tasks;
    }

    public function status(string $id): ?TaskStatus
    {
        $status = $this->entityManager->getConnection()->fetchOne('SELECT status FROM mcp_task WHERE id = ?', [$id]);

        return \is_string($status) ? TaskStatus::tryFrom($status) : null;
    }

    public function transition(string $id, TaskStatus $from, TaskStatus $to): bool
    {
        // What the worker changed in memory first: the refresh below would lose it.
        $this->entityManager->flush();
        $moved = 1 === $this->entityManager->getConnection()->executeStatement(
            'UPDATE mcp_task SET status = ? WHERE id = ? AND status = ?',
            [$to->value, $id, $from->value],
        );
        $task = $this->entityManager->find(Task::class, $id);
        if (null !== $task) {
            $this->entityManager->refresh($task);
        }

        return $moved;
    }

    public function claim(string $id, int $step): bool
    {
        $this->entityManager->flush();
        $claimed = 1 === $this->entityManager->getConnection()->executeStatement(
            'UPDATE mcp_task SET claimed_step = ? WHERE id = ? AND claimed_step < ? AND status IN (?, ?)',
            [$step, $id, $step, TaskStatus::Queued->value, TaskStatus::Running->value],
        );
        $task = $this->entityManager->find(Task::class, $id);
        if (null !== $task) {
            $this->entityManager->refresh($task);
        }

        return $claimed;
    }

    public function addCall(TaskCall $call): void
    {
        $this->entityManager->persist($call);
        $this->entityManager->flush();
    }

    public function calls(string $taskId, int $limit = 50): array
    {
        /** @var list<TaskCall> $calls */
        $calls = $this->entityManager->createQueryBuilder()
            ->select('c')->from(TaskCall::class, 'c')
            ->where('c.taskId = :task')->setParameter('task', $taskId)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return $calls;
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }

    public function delete(string $id): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM mcp_task_call WHERE task_id = ?', [$id]);
        $connection->executeStatement('DELETE FROM mcp_task WHERE id = ?', [$id]);
        $task = $this->entityManager->find(Task::class, $id);
        if (null !== $task) {
            $this->entityManager->detach($task);
        }
    }
}
