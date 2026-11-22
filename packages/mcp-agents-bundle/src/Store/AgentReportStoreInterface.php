<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Store;

use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;

/**
 * The journal of the analyses of the background agents.
 */
interface AgentReportStoreInterface
{
    /**
     * False when this agent already analysed that source: an analysis is never recorded twice.
     */
    public function record(AgentReport $report): bool;

    public function save(): void;

    /**
     * The highest sequence an agent has analysed, 0 when it never ran.
     */
    public function lastSequence(string $agent): int;

    /**
     * @return list<AgentReport> the most recent first
     */
    public function find(?string $agent = null, ?Level $atLeast = null, ?\DateTimeImmutable $since = null, int $limit = 30): array;

    /**
     * @return int how many reports were removed
     */
    public function purgeBefore(\DateTimeImmutable $before): int;
}
