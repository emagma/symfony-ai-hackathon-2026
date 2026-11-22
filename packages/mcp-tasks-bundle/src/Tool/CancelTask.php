<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\McpTasksBundle\TaskView;
use Mcp\Capability\Attribute\McpTool;

/**
 * cancel_task: stops one task of the caller; a running one stops at its next tool call.
 *
 * @internal
 */
#[McpTool(
    name: 'cancel_task',
    description: 'Stop one background task. A running one stops at its next tool call: the call in flight ends,'
        . ' nothing else starts. What it already wrote STAYS written (get_task detail: "calls" lists it); nothing'
        . ' is undone. Also closes a draft or a suspended task. Reasons: not_found, already_over (with its status).',
)]
#[Permission(resource: 'task', action: Action::Write)]
final readonly class CancelTask
{
    public function __construct(
        private TaskManager $manager,
        private TaskAccess $access,
        private TaskView $view,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $taskId): array
    {
        $task = $this->access->own($taskId);
        if (null === $task) {
            return ['ok' => false, 'reason' => 'not_found', 'taskId' => $taskId];
        }
        if (!$this->manager->cancel($task)) {
            return ['ok' => false, 'reason' => 'already_over', 'status' => $task->getStatus()->value];
        }

        return ['ok' => true, 'cancelled' => $this->view->summary($task)];
    }
}
