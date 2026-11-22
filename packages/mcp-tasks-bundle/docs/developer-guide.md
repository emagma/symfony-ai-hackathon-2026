# MCP Tasks Bundle: developer guide

- **Composer name**: `emagma/mcp-tasks-bundle`
- **Namespace**: `Emagma\McpTasksBundle\`
- **Bundle class**: `Emagma\McpTasksBundle\EmagmaMcpTasksBundle`, an `AbstractBundle` (`configure()`, `prependExtension()`, `loadExtension()`, `build()`); there is no Extension nor Configuration class.
- **Config alias**: `emagma_mcp_tasks`
- **Depends on**: `emagma/mcp-core-bundle` `^0.1`, Doctrine ORM and DoctrineBundle, `symfony/process`, `symfony/console`, `symfony/security-http`, `symfony/ai-platform`, `symfony/notifier`, `mcp/sdk`.
- **Not on Messenger**: an orchestrator sequences the steps (below).
- **Suggested**: `doctrine/doctrine-migrations-bundle`, to create the tables with the bundle's migration; `symfony/yaml`, to import its `config/routes.yaml`.
- **Depended on by**: `emagma/mcp-tasks-messenger-bundle`, the orchestrator on Symfony Messenger, and any bundle that prepares ready-made tasks.
- **In the Sylius Assistant stack**: the layer is `Tasks: [Core]` in `apps/mcp-server/deptrac.yaml`.

> **The hardened PHP process is a proof of concept.**
> It relies on PHP's own restrictions (`disable_functions`, `open_basedir`), in the worker's container and under its user, who owns the project.
> A flaw of the PHP engine escapes them.
>
> For production, replace it with a **task server**: a service of its own, apart from the application, that runs the scripts in an environment it protects.
> That environment has no network, no file or secret of the project, and its own limits.
> Its isolation is its own business, whatever its technology.
>
> The worker talks to it through a driver behind `SandboxInterface`.
> The dialogue with the script, `bootstrap.php`, the inspector, the manifest and the delegated rights stay on the server's side, unchanged.

The bundle knows no backend: a task calls whatever MCP Tools the server has.
It knows no orchestrator either: who decides which step runs, and when, is named in `emagma_mcp_tasks.orchestrator`.

## Public API

Everything else is `@internal`.
The classes below are what an orchestrator, a bundle that prepares tasks or a suite of tests may use.

- **Orchestration**: `Orchestration\TaskOrchestratorInterface`, `StepExecutor`, `StepOutcome`, `TaskLifecycle`.
- **Tasks**: `TaskManager`, `TaskStatus`, `TaskNotifierInterface`, `Entity\Task`, `Entity\TaskCall`, `Store\TaskStoreInterface`.
- **Scripts**: `Script\ScriptInspector`, `Script\ScriptRefused`, `Script\TaskFiles`.
- **Contracts to replace**: `Sandbox\SandboxInterface`, `Sandbox\SandboxSessionInterface`, `Delegation\DelegationInterface`, `Delegation\TokenVaultInterface`, `Reading\ScriptReaderInterface`, `Llm\LlmTransformerInterface`, `Runner\ToolInvokerInterface`.
- **Runner**: `Runner\StepRunner`, `Runner\StepResult`, `Runner\TaskLimits`, `Sandbox\ProcessSandbox`.
- **Exceptions**: `Script\ScriptRefused`, `Reading\ReadingFailed`, `Llm\LlmUnavailable`, `Llm\OutputRejected`, `Delegation\DelegationRevoked`, `Runner\ToolCallFailed`, `Sandbox\SandboxTimeout`.
- **For test suites**: everything in `Testing\`.
- **Console**: the commands `mcp:task:step`, `mcp:task:finish` and `mcp:task:interrupted`.
- **Route**: `mcp_tasks_authorize_callback`.

## How it is built

| Class | Role |
|---|---|
| `src/EmagmaMcpTasksBundle.php` | Configuration, prepended defaults, parameters, compiler pass |
| `src/TaskManager.php` | Creates, launches, cancels; never deletes a launched task |
| `src/TaskView.php` | A task as the tools answer it |
| `src/Entity/` | `Task`, `TaskCall`, `TaskAuthorization` |
| `src/Store/DoctrineTaskStore.php` | The tasks and their calls in the database |
| `src/Script/ScriptInspector.php` | Reads the tokens of the scripts: manifest, refusals |
| `src/Script/TaskFiles.php` | The scripts on disk |
| `src/Script/ScriptLinter.php` | Fixes the files of a task in place, when enabled |
| `src/Reading/AiScriptReader.php` | The reading, by a model without tools |
| `src/Orchestration/` | What the orchestrator implements and calls |
| `src/Command/` | `mcp:task:step`, `mcp:task:finish`, `mcp:task:interrupted` |
| `src/Runner/StepRunner.php` | The dialogue with the script; calls the tools |
| `src/Runner/CoreToolInvoker.php` | The tools, through the core's `LocalToolCaller` |
| `src/Sandbox/ProcessSandbox.php` | The hardened PHP process |
| `src/Sandbox/HardenedIni.php` | Its ini, computed by the binary |
| `src/Controller/AuthorizeController.php` | The consent callback, `/tasks/authorize/callback` |
| `src/Delegation/OfflineTokenDelegation.php` | The worker acting as a user |
| `src/Delegation/TokenVault.php` | The offline tokens, encrypted |
| `src/Notification/NotifierTaskNotifier.php` | The email at the end |
| `src/Llm/OutputSchema.php` | The typed answer of `llm_transform` |
| `src/Tool/` | The seven MCP Tools, loaded by namespace |
| `src/Testing/` | Fakes for the suites of the bundles that create tasks |
| `config/services.php` | The explicit service definitions |
| `config/routes.yaml` | The route of the identity provider's callback |
| `migrations/Version20261122000000.php` | Creates the three tables |
| `resources/` | The allow lists, the ini builder, `bootstrap.php`, the linter's rules |

### Services

Services are explicit in `config/services.php`, with ids prefixed by the alias: `emagma_mcp_tasks.store`, `emagma_mcp_tasks.task_manager`, `emagma_mcp_tasks.step_executor`, `emagma_mcp_tasks.lifecycle`, `emagma_mcp_tasks.delegation` and so on.
Class and interface aliases make them injectable, so an orchestrator type-hints `StepExecutor` or `TaskLifecycle`.

Only the tools keep their class as id: they are loaded by namespace with autowiring, so the MCP bundle discovers them.

What the configuration says reaches the services as `emagma_mcp_tasks.*` parameters.
What the host named becomes three aliases the definitions read:

- `emagma_mcp_tasks.orchestrator`, which `TaskOrchestratorInterface` also points to.
- `emagma_mcp_tasks.platform`, the Symfony AI platform.
- `emagma_mcp_tasks.token_handler`, the handler of the firewall.

The script linter is registered as `emagma_mcp_tasks.script_linter` only when it is enabled.

### Creation

`TaskManager::create($owner, $objective, $recap, $params, $steps, &$renamed)`:

1. The files are written first, in `<directory>/<task id>/`.
   `<?php` is added when the model forgot it, and `declare(strict_types=1)` goes at the top.
   With `emagma_mcp_tasks.linter` enabled, `ScriptLinter` runs `path` with `command`, `{task}` being that directory, the only path it is given.
   The server then reads the files back, so that the inspection and the reading see what the linter wrote.
   A linter that fails throws `ProcessFailedException`: no task is created.
   Whatever refuses the task afterwards, its files are removed.
2. `ScriptInspector::assertParams()`: `params.php` is only `const NAME = literal;`.
3. `ScriptInspector::manifest()`: the strings passed as first argument of `call(`.
   It refuses a script that does not parse, a tool name that is not a literal, and the constructs and code listed below.
4. The tools of the manifest are confronted to those the caller may call now (`ToolInvokerInterface::tools()`, without the bundle's own tools except `llm_transform`).
   A name a client prefixed (`shop_search_products`, `mcp__shop__search_products`) that ends, after a `_`, with exactly one of them (the longest) is renamed in the scripts.
   That is `ScriptInspector::renameTools()`, on the literal of `call()` only, before the reading: the human validates the real names, and `create_task` answers them in `renamed`.
   Every other unknown name is refused at once (`tool_not_allowed`), each with the close names.
5. `ScriptReaderInterface::read()`: the reading, which the server starts with `Task <id>`.
   It runs only after the checks: a refused script is neither stored nor sent to a model.
   `AiScriptReader` asks for it in English, whatever language the objective or the data are in.
6. The owner's previous drafts are deleted, the files are written and the task is a `draft`.

Reasons a creation is refused (`ScriptRefused::$reason`): `steps_invalid`, `params_invalid`, `syntax_error`, `construct_forbidden`, `tool_not_literal`, `tool_not_allowed`, and:

| Reason | What the script wrote |
|---|---|
| `function_not_allowed` | A function outside `allowed-functions.php` and not declared in the script |
| `class_not_allowed` | `new X`, `X::...`, `instanceof X` or `catch (X ...)` with X outside `allowed-classes.php` |
| `class_forbidden` | A class, an interface, a trait, an enum, `new class` |
| `dynamic_call` | `$f()`, `$$x`, `$o->$m()`, `X::$m`, `new $c`: a target computed |
| `callable_not_closure` | A function given by its name or as an array to `array_map`, `usort` and the like |
| `not_fully_qualified` | A function or a class of PHP without its leading backslash, every occurrence at once |
| `construct_forbidden` | Also `namespace`, `use`, `goto`, attributes, backticks, a `declare` other than `strict_types=1` |

`call()` and the script's own functions need no leading backslash.
`function () use ($x)` is allowed.

The inspector reads tokens (`PhpToken`, `TOKEN_PARSE`), never runs the code, and never autoloads a name it reads.
It refuses early and says why; the process is the barrier.

### Execution

The bundle hands the flow of the steps to an orchestrator: the service `emagma_mcp_tasks.orchestrator` names.
The orchestrator decides which step runs, and when.
Everything that touches the code and the rights stays here.

- `TaskManager::start()` moves the task to `queued`, then calls `TaskOrchestratorInterface::start()`.
- `TaskManager::cancel()` moves it to `cancelled`, then calls `cancel()`. A draft was never handed over, so it is not called for it.
- A step in flight stops at its next tool call.
- The orchestrator plays each step with `StepExecutor::execute($taskId, $n)`, in order, the next one when the previous answered `Done`.
- It ends the task with `TaskLifecycle::finished()`.

`StepExecutor::execute()`:

1. `Skipped` when the step does not exist, is out of order (`claimed_step` is not `n - 1`), while another is in flight, or when the task is neither `queued` (step 0) nor `running`.
2. `TaskStoreInterface::claim()`: one SQL statement. A step delivered twice plays once (`Skipped`).
3. Step 0: `queued` to `running`, `started_at`.
4. `current_step = n`, `step_in_flight = true`.
5. `DelegationInterface::actAs($owner)`. On `DelegationRevoked`: `suspended`, `Failed`.
6. `StepRunner::run()` opens the sandbox, sends `{play: "000001"}`, then answers each line.
   On an error: `suspended` with the error, the owner told while the process still acts as them, `Failed`.
7. `DelegationInterface::release()`.
   A task cancelled meanwhile: `Skipped`.
   Otherwise `current_step` names the next step, if any, and the answer is `Done`.
8. Whatever happened, `step_in_flight = false`.

`StepExecutor` never plays step `n + 1` and never moves the task to `done`.

| `TaskLifecycle` | What it does |
|---|---|
| `finished($taskId)` | `running` to `done` and the email, once the last step was claimed and is no longer in flight |
| `interrupted($taskId)` | `running` to `suspended` with `interrupted`, and the email |
| `isStepInFlight($taskId)` | True between the claim of a step and its end, read from the database |

The emails of `finished()` and `interrupted()` are sent as the owner for a moment (`actAs()`, then `release()`): the notifier finds their address in the security token.
Without the delegation, nothing is sent; the state is written all the same.

#### An external orchestrator

It never holds the owner's token nor the scripts.
It sequences, the step runs on the server.

- **Its `TaskOrchestratorInterface`**: `start()` starts its workflow (an HTTP call, a job), `cancel()` cancels it.
- **Its service**: name it in `emagma_mcp_tasks.orchestrator`.
- **Its entry into the server**: three commands.
- **Its retries are free**: a step it plays again answers `Skipped`.

| Command | Exit code |
|---|---|
| `mcp:task:step <taskId> <n>` (`n` from 0) | 0 `Done`, 1 `Failed`, 2 `Skipped`, 3 wrong arguments |
| `mcp:task:finish <taskId>` | 0 when the task is `done`, 1 otherwise (its status is printed) |
| `mcp:task:interrupted <taskId>` | 0 when the task is `suspended`, 1 otherwise |

The dialogue with the script is one JSON line each way, over stdin and stdout:

| Direction | Line |
|---|---|
| worker to script | `{play: "000001", seal: "<random>"}` |
| script to worker | `{call: "<tool>", args: {...}, id: 3}` |
| worker to script | `{id: 3, result: {...}}` or `{id: 3, error: "..."}` |
| script to worker | `{ok: true, seal}` or `{ok: false, error: "...", seal}` |

Before each call, `StepRunner` reads the status again, because a cancellation comes from another process.
It checks the manifest and the limit of calls, writes the `mcp_task_call` row, then calls the tool.
`{id, error}` is what it answers for a tool outside the manifest, a `ToolCallFailed`, and a read that answers `reason` without `ok`.
In the script, `call()` throws on `error` and on `ok: false`.

### The sandbox

`php -n -c <hardened>.ini -d open_basedir=<task>:<bootstrap.php> resources/bootstrap.php`, in the directory of the task, with every inherited environment variable unset.

- **One ini for every task** (`HardenedIni`): `<cache dir>/mcp_tasks/hardened-<PHP version>-<hash>.ini`.
  The hash covers the binary, the memory limit and the allow lists, so that a change builds a new one.
  `HardenedIniWarmer`, a cache warmer, builds it at `cache:clear` and `cache:warmup`.
  `path()` builds it when missing.
  Only `open_basedir` is the task's, given on the command line.
- **`resources/build-ini.php`** writes it, run by the same binary with `-n`.
  `disable_functions` is every internal function minus `resources/allowed-functions.php`.
  No `disable_classes`, gone from PHP 8.5: the inspector refuses a class outside `resources/allowed-classes.php` when the task is created.
- **Strict mode**: `TaskFiles` writes `declare(strict_types=1);` at the top of `params.php` and of every step, unless the model wrote it.
- **Proof**: `tests/Unit/Sandbox/ProcessSandboxTest` plays real scripts that try files, the shell, curl, PDO and the environment.

### Delegation

`OfflineTokenDelegation` is OAuth and knows no identity provider.

- `authorizationUrl()` builds a link whose `state` is signed and names the owner.
- `complete()`, the callback, exchanges the code.
  It keeps the offline token only if the handler of the firewall identifies that same owner.
- `actAs()` trades the offline token for an access token and hands it to that handler.
  The handler fills the grants as a request does, then the security token is set.
- `refresh()` does it again once the access token is old, before a tool call.

## Extension points

### A ready-made task from another bundle

```php
use Emagma\McpTasksBundle\Script\ScriptRefused;
use Emagma\McpTasksBundle\TaskManager;

final readonly class ArchiveOldOrders
{
    public function __construct(private TaskManager $tasks)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $owner, int $olderThanDays): array
    {
        try {
            $task = $this->tasks->create(
                $owner,
                'Archive the old orders',
                'On what: the orders older than ' . $olderThanDays . ' days.',
                "<?php\nconst DAYS = " . $olderThanDays . ";\n",
                [['name' => 'archive', 'script' => (string) file_get_contents(__DIR__ . '/archive.php')]],
            );
        } catch (ScriptRefused $refused) {
            return ['ok' => false, 'reason' => $refused->reason, 'message' => $refused->getMessage()];
        }

        return ['ok' => true, 'taskId' => $task->getId(), 'reading' => $task->getReading()];
    }
}
```

### Contracts

| Interface | Default | Replace it to |
|---|---|---|
| `Sandbox\SandboxInterface` | `ProcessSandbox` | Run the scripts in a container |
| `Delegation\DelegationInterface` | `OfflineTokenDelegation` | Get the owner's rights another way |
| `Delegation\TokenVaultInterface` | `TokenVault` | Keep the tokens elsewhere |
| `Reading\ScriptReaderInterface` | `AiScriptReader` | Read the scripts another way |
| `Llm\LlmTransformerInterface` | `AiLlmTransformer` | Ask another model |
| `Runner\ToolInvokerInterface` | `CoreToolInvoker` | Call the tools another way |
| `TaskNotifierInterface` | `NotifierTaskNotifier` | Tell the owner another way |
| `Store\TaskStoreInterface` | `DoctrineTaskStore` | Store the tasks elsewhere |

Re-alias the interface to your own service.

### What a script may use

Add a function to `resources/allowed-functions.php`, a class to `resources/allowed-classes.php`.
The ini of a task is rebuilt each time a step starts.

## Configuration reference

See the user guide for every key.
Two need more.

### The orchestrator

```yaml
emagma_mcp_tasks:
    orchestrator: App\Task\MyOrchestrator   # the id of a TaskOrchestratorInterface service
```

There is no default here: an orchestrator bundle prepends its own.
Without one, the container is not built.

### The linter

```yaml
emagma_mcp_tasks:
    linter:
        enabled: true
        path: '%kernel.project_dir%/vendor/bin/ecs'
        command: ~
```

- **`path`** is the linter's binary.
  `LinterPass` checks it is an executable file when the container is built.
  An enabled linter that is not installed stops the server at start.
- **`command`** is a list of arguments, never a shell line.
  `{task}` is replaced by the directory of the task and is required.
  The default is ECS with the bundle's rules (`resources/linter/ecs.php`: `NativeFunctionInvocationFixer`, `FullyQualifiedStrictTypesFixer`).
- **In production** the binary must be installed there (`require`, not `require-dev`).

### What the bundle prepends

| Target | What |
|---|---|
| `emagma_mcp_core` | Permission domain `core` for `Emagma\McpTasksBundle\` |
| `emagma_mcp_core` server | The tool namespace `Emagma\McpTasksBundle\Tool\` |
| `doctrine.orm` | The attribute mapping `EmagmaMcpTasksBundle` of `src/Entity` |
| `doctrine_migrations` | The path of `migrations/`, when the migrations bundle is there |

The route `/tasks/authorize/callback` is imported by the host: `@EmagmaMcpTasksBundle/config/routes.yaml`.
Its controller is the service `emagma_mcp_tasks.controller.authorize`.

## Data

| Table | Holds |
|---|---|
| `mcp_task` | One task: owner, objective, summary, reading, manifest, steps, status, progress, error |
| `mcp_task_call` | One tool call of a script: step, tool, arguments, outcome or error, duration |
| `mcp_task_authorization` | One offline token per owner, encrypted (AES-256-GCM, `kernel.secret`) |

The scripts are files: `<directory>/<task id>/params.php` and `steps/<number on six digits>/run.php`.
The ini is shared, in the cache (above).

The migration `Version20261122000000` creates the three tables as the mapping describes them, on MySQL, MariaDB and SQLite.
A table that exists is skipped, so a host that created them with migrations of its own runs it as a no-op, recorded as done.
Its `down()` keeps the tables.
On another platform, `doctrine:schema:update --dump-sql` gives the SQL.

Entities are not `final`, because Doctrine builds its proxies by inheritance.
Fields set once are `readonly`, except `Task::$createdAt`: the store refreshes the task, and a refresh hydrates a new object that Doctrine refuses in a readonly property.

## Tests and quality

The suite runs on its own, on the lowest and the highest dependencies, with a minimal kernel on SQLite (`tests/Integration/Fixture/TestKernel.php`).

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

- **`tests/Unit/TaskLifecycleTest.php`**: a task from creation to its end, its scripts played in the real process against tools in memory through `RecordingOrchestrator`.
  It covers steps in order, first error, tool outside the manifest, right withdrawn, cancellation, limit and delegation.
  It also replaces a step on disk after its creation: what the process faces if a script got past the inspector.
- **`tests/Unit/Orchestration/StepExecutorTest.php`**: what any orchestrator may rely on.
  The step in flight, a step out of range or played twice, the end only after the last step, the interruption.
- **`tests/Unit/Command/OrchestratorCommandsTest.php`**: the exit codes of the three commands.
- **`tests/Unit/EmagmaMcpTasksBundleTest.php`**: the configuration, the aliases, the linter, what the bundle prepends.
- **`tests/Unit/Sandbox/`**: what a script cannot reach, and the one ini shared by the tasks.
- **`tests/Unit/Script/ScriptInspectorTest.php`**: the manifest, every refusal, and what a script needs staying allowed.
- **`tests/Unit/OfflineTokenDelegationTest.php`**: the link, the callback, the refresh, the revocation.
- **`tests/Unit/Tool/`**: the tools and `OutputSchema`.
- **`tests/Unit/Conformance/`**: the core's conformance cases on the tools.
- **`tests/Integration/`**: the container compiled in a host, the entities on a database, the migration against the mapping, the route.

Helpers shipped in `Testing\`: `InMemoryTaskStore`, `FakeTools`, `FakeDelegation`, `FakeScriptReader`, `RecordingOrchestrator` (records `start()` and `cancel()`, and `play()` plays the tasks started as an orchestrator does), `RecordingTaskNotifier` and `InMemoryTokenVault`.

## Traps

- **No class is disabled in the process.**
  A script that got past the inspector could use any class compiled in the binary.
  `open_basedir` still confines its files, `allow_url_fopen=0` its URLs, and a disabled function stays unreachable, Reflection included.
- **`exit` and `die` cannot be disabled** since PHP 8.4: the inspector refuses them in a script.
- **A script can write on stdout by itself** if it gets past the inspector, because `bootstrap.php` needs `fwrite`.
  A forged call line goes through the manifest check like any other.
  A forged last line is refused: the worker sends a random `seal` with `play`, `bootstrap.php` keeps it in a function scope the script cannot read and returns it in the last line.
- **A step may last hours**: the orchestrator must not hand it to another runner meanwhile.
  `claim()` covers a second delivery.
- **The registry is empty in a worker** until the server is built: `LocalToolCaller` is injected the server for that only.
- **Tools are called without a session**: no elicitation, and `LocalToolCaller` sets `confirm`.
  The owner's confirmation of `start_task` stands for it, bounded by the manifest.
- **The audit entries of a task carry each call's own `batchId`**, not the task's id.
  The link is the outcome of the call in `mcp_task_call`.
- **The cost of the `llm_transform` calls is not measured.**
- **In a deployment the server and the worker must share `directory`**, as a volume: the image bakes the application in.
