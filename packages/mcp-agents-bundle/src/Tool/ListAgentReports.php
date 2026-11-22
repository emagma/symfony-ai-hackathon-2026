<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tool;

use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use Emagma\McpAgentsBundle\Store\AgentReportStoreInterface;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Lists what the background agents found, the most recent first.
 *
 * @internal
 */
#[McpTool(
    name: 'list_agent_reports',
    description: 'List what the background agents of the server found on their own (a suspicious customer'
        . ' account, ...), the most recent first: agent, level (low, medium, high), subject {type, id}, signals,'
        . ' explanation, createdAt, alerted (whether an alert was sent). Agents only report: they change'
        . ' nothing in the shop. Filters: agent (e.g. "fraud"), level (the lowest level to list, default'
        . ' medium: low ones are the analyses that found nothing), since (ISO-8601). At most 50 per call.'
        . ' Follow subject.id with the matching get_* tool (get_customers for a customer).',
)]
#[Permission(resource: 'agent_report', action: Action::Read)]
final readonly class ListAgentReports
{
    public function __construct(
        private AgentReportStoreInterface $reports,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function __invoke(
        ?string $agent = null,
        #[Schema(enum: ['low', 'medium', 'high'])]
        string $level = 'medium',
        ?string $since = null,
        #[Schema(minimum: 1, maximum: 50)]
        int $limit = 30,
    ): array {
        try {
            $from = null === $since || '' === $since ? null : new \DateTimeImmutable($since);
        } catch (\Exception) {
            return ['items' => [], 'total' => 0, 'reason' => 'invalid_since', 'hint' => 'since is an ISO-8601 date, e.g. 2026-11-14T00:00:00Z.'];
        }

        $reports = $this->reports->find($agent, Level::tryFrom($level) ?? Level::Medium, $from, max(1, min(50, $limit)));

        return [
            'items' => array_map(static fn (AgentReport $report): array => [
                'agent' => $report->getAgent(),
                'level' => $report->getLevel()->value,
                'subject' => ['type' => $report->getSubjectType(), 'id' => $report->getSubjectId()],
                'signals' => $report->getSignals(),
                'explanation' => $report->getExplanation(),
                'createdAt' => $report->getCreatedAt()->format(\DATE_ATOM),
                'alerted' => null !== $report->getNotifiedAt(),
            ], $reports),
            'total' => \count($reports),
        ];
    }
}
