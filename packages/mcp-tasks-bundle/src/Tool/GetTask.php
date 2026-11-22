<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpTasksBundle\TaskView;
use Mcp\Capability\Attribute\McpTool;

/**
 * get_task: the state of one task of the caller, its calls or its scripts on request.
 *
 * @internal
 */
#[McpTool(
    name: 'get_task',
    description: 'Get one background task by id: status, reading, manifest, the error it stopped on, `next` (what'
        . ' can be done now) and `progress`: the step being played out of how many, the number of tool calls made'
        . ' and the last one. The step is the only progress: the server does not know what remains inside a step,'
        . ' so never give a percentage. `detail: "calls"` adds the last tool calls (arguments, outcome or error;'
        . ' `uncertain` marks a call the worker did not see the end of), `detail: "scripts"` the scripts as'
        . ' validated. Returns {found: false, taskId} for an unknown task or someone else\'s.',
)]
#[Permission(resource: 'task', action: Action::Read)]
final readonly class GetTask
{
    public function __construct(
        private TaskAccess $access,
        private TaskView $view,
    ) {
    }

    /**
     * @param string|null $detail "calls" or "scripts"
     * @param int         $limit  how many calls, 1 to 50, with detail: "calls"
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $taskId, ?string $detail = null, int $limit = 20): array
    {
        $task = $this->access->own($taskId);
        if (null === $task) {
            return ['found' => false, 'taskId' => $taskId];
        }

        return ['found' => true] + $this->view->detail($task) + match ($detail) {
            'calls' => ['calls' => $this->view->calls($task, max(1, min(50, $limit)))],
            'scripts' => $this->view->scripts($task),
            default => [],
        };
    }
}
