<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Store;

use Doctrine\ORM\EntityManagerInterface;
use Emagma\McpSyliusEventsBundle\Entity\ShopEvent;

/**
 * The journal in the host's database: a known event id is not written twice.
 *
 * @internal
 */
final readonly class DoctrineShopEventStore implements ShopEventStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function record(ShopEvent $event): bool
    {
        $known = $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM mcp_shop_event WHERE event_id = ?', [$event->getEventId()]);
        if (false !== $known) {
            return false;
        }
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return true;
    }

    public function after(string $name, int $sequence, int $limit = 50): array
    {
        /** @var list<ShopEvent> $events */
        $events = $this->entityManager->createQueryBuilder()
            ->select('e')->from(ShopEvent::class, 'e')
            ->where('e.name = :name')->andWhere('e.id > :sequence')
            ->setParameter('name', $name)->setParameter('sequence', $sequence)
            ->orderBy('e.id', 'ASC')->setMaxResults($limit)
            ->getQuery()->getResult();

        return $events;
    }
}
