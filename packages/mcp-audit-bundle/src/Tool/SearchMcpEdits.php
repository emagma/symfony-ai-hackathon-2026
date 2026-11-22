<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tool;

use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\Output\McpEditSummaryOutput;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\WidenedBy;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Mcp\Capability\Attribute\McpTool;

/**
 * Lists the entries the caller reaches, the latest first: `total` counts what the caller may see.
 *
 * @internal
 */
#[McpTool(
    name: 'search_mcp_edits',
    description: 'Search MCP write tool audit entries (lean : id + createdAt + tool + target + summary + reverted'
        . ' flag). Filters: targetType (e.g. "taxon"), targetCode (e.g. "dresses"), sessionId, batchId (every item of one batch tool call), since'
        . ' (ISO-8601 datetime ; everything strictly after is returned), includeReverted (default true ;'
        . ' false to hide entries already reverted). Ordered DESC by createdAt. Use this to discover edits'
        . ' the user wants to revert via revert_mcp_edit. Detail: get_mcp_edits (ids). Lists the caller\'s own'
        . ' edits, every edit under the audit_all grant. Customers and administrators are targeted by id.',
)]
#[Permission(resource: 'audit', action: Action::Read)]
#[WidenedBy(resource: AuditScope::ALL, action: Action::Read)]
final readonly class SearchMcpEdits
{
    public function __construct(
        private AuditRepositoryInterface $repository,
        private AuditScope $scope,
    ) {
    }

    /**
     * @return array{
     *     items: list<object>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $targetType = null,
        ?string $targetCode = null,
        ?string $sessionId = null,
        ?string $batchId = null,
        ?string $since = null,
        bool $includeReverted = true,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);
        $actor = $this->scope->actor(Action::Read);
        if (false === $actor) {
            return ['items' => [], 'total' => 0, 'page' => $pager->page, 'hasMore' => false];
        }

        $filters = ['includeReverted' => $includeReverted];
        if (null !== $actor) {
            $filters['actor'] = $actor;
        }
        if (null !== $targetType && '' !== $targetType) {
            $filters['targetType'] = $targetType;
        }
        if (null !== $targetCode && '' !== $targetCode) {
            $filters['targetCode'] = $targetCode;
        }
        if (null !== $sessionId && '' !== $sessionId) {
            $filters['sessionId'] = $sessionId;
        }
        if (null !== $batchId && '' !== $batchId) {
            $filters['batchId'] = $batchId;
        }
        if (null !== $since && '' !== $since) {
            try {
                $filters['since'] = new \DateTimeImmutable($since);
            } catch (\Exception) {
                // An invalid date is dropped, not refused.
            }
        }

        $result = $this->repository->paginate($filters, $pager->page, $pager->itemsPerPage);

        $items = array_map(McpEditSummaryOutput::fromEntry(...), $result['items']);

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $pager->page,
            'hasMore' => ($pager->page * $pager->itemsPerPage) < $result['total'],
        ];
    }
}
