<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpTasksBundle\Reading\ReadingFailed;
use Emagma\McpTasksBundle\Script\ScriptRefused;
use Emagma\McpTasksBundle\TaskManager;
use Mcp\Capability\Attribute\McpTool;

/**
 * create_task: stores the scripts a model wrote as a draft, with the reading the human validates.
 *
 * @internal
 */
#[McpTool(
    name: 'create_task',
    description: 'Prepare a background task for a LONG treatment (hundreds of items, hours): PHP scripts YOU write,'
        . ' played later by the server with the caller\'s rights. Nothing runs yet: the task is a draft, and the'
        . ' answer carries `reading`, what the scripts do in plain language as the server read them. Show that'
        . ' reading to the human as it is; start_task launches the task once they agree. A new create_task'
        . ' replaces the caller\'s previous draft. Follow the prepare-task skill (get_skill) before writing.'
        . ' `params`: a PHP file of `const NAME = value;` only (the values decided with the human: codes, locales,'
        . ' thresholds, never a token). `steps`: [{name, script}], played in order, each script once, in its own'
        . ' process. Start a new step where the next phase needs nothing in memory, only params and the shop; a'
        . ' pass over a list stays in one step.'
        . ' A script is plain PHP (functions, loops, exceptions) that starts with <?php and acts only through'
        . ' call(\'<tool>\', [arguments]): it returns the tool\'s answer as an array and THROWS when the tool'
        . ' answers ok: false, a reason, or cannot be called; a batch tool answers ok: true with one result per'
        . ' item, which the script must check itself. An uncaught exception stops the whole task: its message is'
        . ' what the human reads, so name the item at fault in it. The tool name must be a plain string; eval,'
        . ' include, require, echo and exit are refused; only string, array, number, json, date and regex'
        . ' functions exist (no file, no network). No class of your own; a function you reuse is declared with'
        . ' its name; a function given to array_map, usort... is a closure; every call names its target (no'
        . ' $f(), no $$x). You MUST write the functions and classes of PHP with a leading backslash'
        . ' (\\sprintf(), new \\RuntimeException()). Scripts run in strict mode. Never pass `confirm`: the launch is the confirmation.'
        . ' Reasons: no_actor, steps_invalid, params_invalid, syntax_error, construct_forbidden, tool_not_literal,'
        . ' tool_not_allowed, function_not_allowed, class_not_allowed, class_forbidden, dynamic_call,'
        . ' callable_not_closure, not_fully_qualified, reading_failed; `message` says where, every faulty tool at once. A tool named'
        . ' with the prefix your client adds (shop_search_products) is renamed to this server\'s (`renamed`).',
)]
#[Permission(resource: 'task', action: Action::Write)]
final readonly class CreateTask
{
    public function __construct(
        private TaskManager $manager,
        private TaskAccess $access,
    ) {
    }

    /**
     * @param string                                    $objective what the task is for, one sentence
     * @param string                                    $recap     the summary the human confirmed: on what, what changes, what does not
     * @param string                                    $params    the PHP source of params.php
     * @param list<array{name: string, script: string}> $steps     the steps in order; name in lowercase letters, digits and dashes
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $objective, string $recap, string $params, array $steps): array
    {
        $actor = $this->access->actor();
        if (null === $actor) {
            return ['ok' => false, 'reason' => 'no_actor', 'message' => 'A task belongs to an identified user.'];
        }

        try {
            $task = $this->manager->create($actor, $objective, $recap, $params, $steps, $renamed);
        } catch (ScriptRefused $refused) {
            return ['ok' => false, 'reason' => $refused->reason, 'message' => $refused->getMessage()];
        } catch (ReadingFailed $failed) {
            return ['ok' => false, 'reason' => 'reading_failed', 'message' => $failed->getMessage()];
        }

        return [
            'ok' => true,
            'taskId' => $task->getId(),
            'status' => $task->getStatus()->value,
            'reading' => $task->getReading(),
            'manifest' => $task->getManifest(),
        ] + ([] === $renamed ? [] : ['renamed' => $renamed]) + [
            'next' => 'Nothing runs yet. Show the reading to the human as it is (it starts with the task id), with the tools of the manifest; name the task by its id, then wait for their answer. start_task launches it, with this taskId, once they agree.',
        ];
    }
}
