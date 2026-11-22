<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Testing;

use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use Emagma\McpAgentsBundle\Store\AgentReportStoreInterface;

/**
 * The journal in memory, for the suites of the bundles that bring agents. `$reports` is public for assertions.
 */
final class InMemoryAgentReportStore implements AgentReportStoreInterface
{
    /** @var list<AgentReport> */
    public array $reports = [];

    public function record(AgentReport $report): bool
    {
        foreach ($this->reports as $known) {
            if ($known->getAgent() === $report->getAgent() && $known->getSource() === $report->getSource()) {
                return false;
            }
        }
        $this->reports[] = $report;

        return true;
    }

    public function save(): void
    {
    }

    public function lastSequence(string $agent): int
    {
        $sequences = array_map(
            static fn (AgentReport $report): int => $report->getSourceSequence(),
            array_filter($this->reports, static fn (AgentReport $report): bool => $report->getAgent() === $agent),
        );

        return [] === $sequences ? 0 : max($sequences);
    }

    public function find(?string $agent = null, ?Level $atLeast = null, ?\DateTimeImmutable $since = null, int $limit = 30): array
    {
        $found = array_values(array_filter(
            $this->reports,
            static fn (AgentReport $report): bool => (null === $agent || $report->getAgent() === $agent) &&
                (null === $atLeast || $report->getLevel()->rank() >= $atLeast->rank()) &&
                (null === $since || $report->getCreatedAt() >= $since),
        ));
        usort($found, static fn (AgentReport $a, AgentReport $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        return \array_slice($found, 0, $limit);
    }

    public function purgeBefore(\DateTimeImmutable $before): int
    {
        $kept = array_values(array_filter($this->reports, static fn (AgentReport $report): bool => $report->getCreatedAt() >= $before));
        $removed = \count($this->reports) - \count($kept);
        $this->reports = $kept;

        return $removed;
    }
}
