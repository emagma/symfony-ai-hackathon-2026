<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tool;

use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\Output\McpEditOutput;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\WidenedBy;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads in full the entries the caller reaches; another's entry is listed as missing, like one that does not exist.
 *
 * @internal
 */
#[McpTool(
    name: 'get_mcp_edits',
    description: 'Get MCP audit entries by id, at most 50 per call, with their before / after / diff snapshots.'
        . ' Returns {found: [...], missing: [ids]}. Discover ids with search_mcp_edits; read an entry before'
        . ' revert_mcp_edit to confirm what the revert will do. Another caller\'s entry is missing unless the'
        . ' audit_all grant is held.',
)]
#[Permission(resource: 'audit', action: Action::Read)]
#[WidenedBy(resource: AuditScope::ALL, action: Action::Read)]
final readonly class GetMcpEdits
{
    private const int MAX_IDS = 50;

    public function __construct(
        private AuditRepositoryInterface $repository,
        private AuditScope $scope,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return array{found: list<McpEditOutput>, missing: list<int>, reason?: string}
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'integer'], minItems: 1)]
        array $ids,
    ): array {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if (\count($ids) > self::MAX_IDS) {
            return ['found' => [], 'missing' => [], 'reason' => 'too_many_ids'];
        }
        if ([] === $ids) {
            return ['found' => [], 'missing' => []];
        }

        // Another's entry is missing, like one that does not exist.
        $actor = $this->scope->actor(Action::Read);
        $byId = [];
        foreach (false === $actor ? [] : $this->repository->findByIds($ids, $actor) as $entry) {
            $byId[$entry->getId()] = $entry;
        }

        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $found[] = McpEditOutput::fromEntry($byId[$id]);
            } else {
                $missing[] = $id;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
