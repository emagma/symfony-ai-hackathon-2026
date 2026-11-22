<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\McpAuditBundle\Entity\AuditEntry;

/**
 * Not a ServiceEntityRepository, so that its service keeps the bundle's id.
 * The entity manager is looked up at each call: a manager the host resets (a worker between two messages) is picked up.
 *
 * @internal
 */
final readonly class AuditRepository implements AuditRepositoryInterface
{
    public function __construct(
        private ManagerRegistry $registry,
    ) {
    }

    public function find(int $id): ?AuditEntry
    {
        return $this->manager()->find(AuditEntry::class, $id);
    }

    public function findByIds(array $ids, ?string $actor = null): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder()
            ->andWhere('e.id IN (:ids)')->setParameter('ids', $ids);
        if (null !== $actor) {
            $qb->andWhere('e.actor = :actor')->setParameter('actor', $actor);
        }

        /** @var list<AuditEntry> $entries */
        $entries = $qb->getQuery()->getResult();

        return $entries;
    }

    public function findByBatch(string $batchId, ?string $actor = null): array
    {
        $qb = $this->createQueryBuilder()
            ->andWhere('e.batchId = :batchId')->setParameter('batchId', $batchId)
            ->orderBy('e.id', 'DESC');
        if (null !== $actor) {
            $qb->andWhere('e.actor = :actor')->setParameter('actor', $actor);
        }

        /** @var list<AuditEntry> $entries */
        $entries = $qb->getQuery()->getResult();

        return $entries;
    }

    public function countBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->andWhere('e.createdAt < :cutoff')->setParameter('cutoff', $cutoff)
            ->getQuery()->getSingleScalarResult();
    }

    public function purgeBefore(\DateTimeImmutable $cutoff): int
    {
        $deleted = $this->manager()->createQueryBuilder()
            ->delete(AuditEntry::class, 'e')
            ->andWhere('e.createdAt < :cutoff')->setParameter('cutoff', $cutoff)
            ->getQuery()->execute();
        \assert(\is_int($deleted));

        return $deleted;
    }

    /**
     * Scanned in PHP, not in SQL: there is no JSON search common to MySQL, PostgreSQL and SQLite.
     */
    public function findConcerning(string $type, string $id, array $referenceSuffixes = []): array
    {
        $matches = [];

        /** @var AuditEntry $entry */
        foreach ($this->createQueryBuilder()->orderBy('e.id', 'ASC')->getQuery()->toIterable() as $entry) {
            if ($type === $entry->getTargetType() && $id === $entry->getTargetCode()) {
                $matches[] = $entry;

                continue;
            }
            foreach ($referenceSuffixes as $suffix) {
                if (self::references($entry->getBeforeState(), $suffix) || self::references($entry->getAfterState(), $suffix)) {
                    $matches[] = $entry;

                    break;
                }
            }
        }

        return $matches;
    }

    public function paginate(array $filters, int $page, int $itemsPerPage): array
    {
        $qb = $this->createQueryBuilder();
        if (isset($filters['targetType']) && '' !== $filters['targetType']) {
            $qb->andWhere('e.targetType = :tt')->setParameter('tt', $filters['targetType']);
        }
        if (isset($filters['targetCode']) && '' !== $filters['targetCode']) {
            $qb->andWhere('e.targetCode = :tc')->setParameter('tc', $filters['targetCode']);
        }
        if (isset($filters['sessionId']) && '' !== $filters['sessionId']) {
            $qb->andWhere('e.sessionId = :sid')->setParameter('sid', $filters['sessionId']);
        }
        if (isset($filters['batchId']) && '' !== $filters['batchId']) {
            $qb->andWhere('e.batchId = :bid')->setParameter('bid', $filters['batchId']);
        }
        if (isset($filters['since'])) {
            $qb->andWhere('e.createdAt >= :since')->setParameter('since', $filters['since']);
        }
        if (isset($filters['actor'])) {
            $qb->andWhere('e.actor = :actor')->setParameter('actor', $filters['actor']);
        }
        if (false === ($filters['includeReverted'] ?? true)) {
            $qb->andWhere('e.revertedBy IS NULL');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $qb->orderBy('e.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);

        /** @var list<AuditEntry> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<array-key, mixed> $snapshot
     */
    private static function references(array $snapshot, string $suffix): bool
    {
        foreach ($snapshot as $value) {
            if (\is_string($value) && str_ends_with($value, $suffix)) {
                return true;
            }
            if (\is_array($value) && self::references($value, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(AuditEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('%s is not mapped by a Doctrine ORM entity manager.', AuditEntry::class));
        }

        return $manager;
    }

    private function createQueryBuilder(): QueryBuilder
    {
        return $this->manager()->createQueryBuilder()->select('e')->from(AuditEntry::class, 'e');
    }
}
