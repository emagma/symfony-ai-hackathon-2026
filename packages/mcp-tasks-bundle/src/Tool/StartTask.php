<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\McpTasksBundle\TaskStatus;
use Emagma\McpTasksBundle\TaskView;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/**
 * start_task: launches a draft once the human validated its reading.
 *
 * @internal
 */
#[McpTool(
    name: 'start_task',
    description: 'Launch a draft task. taskId is the one create_task returned: NEVER call start_task in the same'
        . ' message as create_task, nor with an id you have not read. Call it only after the human agreed to the'
        . ' reading you showed them; a task written again (a fix, a relaunch) is a new reading to show and agree.'
        . ' The human is shown its reading and confirms ONCE for everything its scripts'
        . ' will write through the tools of its manifest, then the worker plays it in the background with the'
        . ' caller\'s rights of each moment. Confirmation is REQUIRED. The first time, the answer is'
        . ' authorization_required with a `url`: give it to the human as a link, they open it once to let the'
        . ' server act for them while they are away, then call start_task again. Follow the task with get_task.'
        . ' Reasons: not_found, not_a_draft (with the status), authorization_required, cancelled, declined.',
)]
#[Permission(resource: 'task', action: Action::Write)]
final readonly class StartTask
{
    use WriteConfirmationTrait;

    public function __construct(
        private TaskManager $manager,
        private TaskAccess $access,
        private TaskView $view,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $taskId, bool $confirm = false, ?RequestContext $context = null): array
    {
        $task = $this->access->own($taskId);
        if (null === $task) {
            return ['ok' => false, 'reason' => 'not_found', 'taskId' => $taskId];
        }
        if (TaskStatus::Draft !== $task->getStatus()) {
            return ['ok' => false, 'reason' => 'not_a_draft', 'status' => $task->getStatus()->value];
        }

        // The link first: asking the human to confirm a launch the server could not make would make them confirm twice.
        $url = $this->manager->authorizationUrl($task);
        if (null !== $url) {
            return self::authorizationRequired($url);
        }

        $preview = $task->getReading() . "\n\nTools this task may call: " . implode(', ', $task->getManifest()) . '.';
        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Launch this background task?',
            'Launch: ' . mb_substr($task->getObjective(), 0, 80),
            $preview,
            'Nothing was launched. Re-invoke with confirm: true to launch the task.',
        );
        if (null !== $refusal) {
            return $refusal + ['preview' => $preview];
        }

        $url = $this->manager->start($task);
        if (null !== $url) {
            return self::authorizationRequired($url);
        }

        return ['ok' => true, 'started' => $this->view->summary($task)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function authorizationRequired(string $url): array
    {
        return [
            'ok' => false,
            'reason' => 'authorization_required',
            'url' => $url,
            'hint' => 'Give this link to the human as a link. They open it once, then call start_task again.',
        ];
    }
}
