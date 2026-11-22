<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskStatus;
use Emagma\McpTasksBundle\TaskView;
use Mcp\Capability\Attribute\McpTool;

/**
 * list_tasks: the tasks of the caller, the most recent first.
 *
 * @internal
 */
#[McpTool(
    name: 'list_tasks',
    description: 'List the background tasks the caller created, the most recent first: objective, status (draft,'
        . ' queued, running, suspended, done, cancelled) and progress. A task outlives the conversation that'
        . ' launched it: this is how to find it again.',
)]
#[Permission(resource: 'task', action: Action::Read)]
final readonly class ListTasks
{
    public function __construct(
        private TaskStoreInterface $store,
        private TaskAccess $access,
        private TaskView $view,
    ) {
    }

    /**
     * @param string|null $status only the tasks in this status
     * @param int         $limit  1 to 50
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function __invoke(?string $status = null, int $limit = 20): array
    {
        $actor = $this->access->actor();
        $filter = null === $status ? null : TaskStatus::tryFrom($status);
        if (null === $actor || (null !== $status && null === $filter)) {
            return ['items' => [], 'total' => 0];
        }

        $items = array_map($this->view->summary(...), $this->store->findByOwner($actor, $filter, max(1, min(50, $limit))));

        return ['items' => $items, 'total' => \count($items)];
    }
}
