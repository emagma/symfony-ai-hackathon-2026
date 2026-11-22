<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Store;

use Doctrine\ORM\EntityManagerInterface;
use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;

/**
 * The journal on Doctrine. A report is unique per agent and source.
 *
 * @internal
 */
final readonly class DoctrineAgentReportStore implements AgentReportStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function record(AgentReport $report): bool
    {
        $known = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM mcp_agent_report WHERE agent = ? AND source = ?',
            [$report->getAgent(), $report->getSource()],
        );
        if (false !== $known) {
            return false;
        }
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return true;
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }

    public function lastSequence(string $agent): int
    {
        $last = $this->entityManager->getConnection()->fetchOne('SELECT MAX(source_sequence) FROM mcp_agent_report WHERE agent = ?', [$agent]);

        return is_numeric($last) ? (int) $last : 0;
    }

    public function find(?string $agent = null, ?Level $atLeast = null, ?\DateTimeImmutable $since = null, int $limit = 30): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('r')->from(AgentReport::class, 'r')
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit);
        if (null !== $agent) {
            $builder->andWhere('r.agent = :agent')->setParameter('agent', $agent);
        }
        if (null !== $atLeast) {
            $levels = array_values(array_map(
                static fn (Level $level): string => $level->value,
                array_filter(Level::cases(), static fn (Level $level): bool => $level->rank() >= $atLeast->rank()),
            ));
            $builder->andWhere('r.level IN (:levels)')->setParameter('levels', $levels);
        }
        if (null !== $since) {
            $builder->andWhere('r.createdAt >= :since')->setParameter('since', $since);
        }

        /** @var list<AgentReport> $reports */
        $reports = $builder->getQuery()->getResult();

        return $reports;
    }

    public function purgeBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM mcp_agent_report WHERE created_at < ?',
            [$before->format('Y-m-d H:i:s')],
        );
    }
}
