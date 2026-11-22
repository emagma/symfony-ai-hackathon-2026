# MCP Tasks Messenger Bundle: developer guide

- **Composer name**: `emagma/mcp-tasks-messenger-bundle`
- **Namespace**: `Emagma\McpTasksMessengerBundle`
- **Config alias**: `emagma_mcp_tasks_messenger` (no configuration tree)
- **Depends on**: `emagma/mcp-tasks-bundle` `^0.1`, `symfony/messenger`, `symfony/doctrine-messenger`, `doctrine/dbal` (the listener's missing table).
- **Depended on by**: the host application only.
- **In the Sylius Assistant stack**: the layer is `TasksMessenger: [Core, Tasks]` in `apps/mcp-server/deptrac.yaml`, and `Tasks` does not know it.

It is the orchestrator of the tasks bundle on Symfony Messenger.
It decides which step runs, and when.
The step itself is played by the tasks bundle (`StepExecutor`).

## Public API

Almost everything is `@internal`.

- **`Message\RunStep`**: one step of one task (`taskId`, `step` from 0).
  It is stored serialized by the database transports: its class name and namespace must not change.
- **`EmagmaMcpTasksMessengerBundle`**: the constants `ORCHESTRATOR`, `TRANSPORT`, `FAILURE_TRANSPORT`, `FAILURE_DSN` and `DEFAULT_DSN`.

## How it is built

| Class | Role |
| --- | --- |
| `EmagmaMcpTasksMessengerBundle` | The prepends below and the services |
| `MessengerOrchestrator` | `TaskOrchestratorInterface`: `start()` sends `RunStep(id, 0)`, `cancel()` does nothing |
| `Handler\RunStepHandler` | `StepExecutor::execute()`; on `Done`, sends `RunStep(n + 1)` or ends the task after the last |
| `EventListener\InterruptedTasksListener` | On `WorkerStartedEvent` of a worker of `mcp_tasks`: `interrupted()` for each running task with a step in flight |

Services are explicit in `config/services.php`, with ids prefixed by the alias:

- `emagma_mcp_tasks_messenger.orchestrator`, the id the tasks bundle's alias points to by default (the class is an alias to it),
- `emagma_mcp_tasks_messenger.run_step_handler`, tagged `messenger.message_handler`,
- `emagma_mcp_tasks_messenger.interrupted_tasks_listener`, tagged `kernel.event_listener`.

Behaviour worth knowing:

- **`cancel()` does nothing.**
  The step in flight stops at its next tool call, and the message of the next step plays nothing (`Skipped`, the task is no longer running).
- **`Failed` and `Skipped` send nothing.**
  The tasks bundle already wrote the state of the task.
- **The listener reads `step_in_flight`.**
  A task between two steps is running with nothing in flight: its next message waits in the queue, and it is left alone.
- **No table, no task.**
  On a database not migrated yet (a fresh install, where the consumers start before the migrations), the store answers `TableNotFoundException`.
  The listener suspends nothing and the worker starts.
  Any other error of the store still stops the worker.

## What the bundle prepends

| Target | What |
| --- | --- |
| `emagma_mcp_tasks` | `orchestrator: emagma_mcp_tasks_messenger.orchestrator`, a default the host may override |
| `framework.messenger` | The transport `mcp_tasks`: `%env(MCP_TASKS_TRANSPORT_DSN)%`, `max_retries: 0`, failure transport `failed_mcp_tasks`; `RunStep` routed to it |
| `framework.messenger` | The transport `failed_mcp_tasks`: `doctrine://default?queue_name=failed_mcp_tasks` |
| parameter | `env(MCP_TASKS_TRANSPORT_DSN)`: the default DSN, unless the host sets it |

## Extension points

None of its own.
Another orchestrator replaces this bundle: unregister it and set `emagma_mcp_tasks.orchestrator` (see the tasks bundle's developer guide).

## Tests and quality

- **`tests/Unit/EmagmaMcpTasksMessengerBundleTest.php`**: the prepends (no retry, `failed_mcp_tasks`, the host's own DSN) and the services.
- **`tests/Unit/RunStepHandlerTest.php`**: the steps chained one message each and the task ended, a message delivered twice, a failed step, a cancellation.
  The scripts run in the real hardened process, against tools in memory.
- **`tests/Unit/InterruptedTasksListenerTest.php`**: a task cut during its step is suspended, a task between two steps is left running, another transport's worker changes nothing, a missing table suspends nothing.
- **`tests/Integration/BundleWiringTest.php`**: the bundle booted beside the tasks bundle on a minimal kernel, with no `orchestrator` set: it is the default one, the handler and listener are wired, a started task sends `RunStep` to `mcp_tasks`.
- **`tests/RecordingBus.php`**: a `MessageBusInterface` that keeps the messages.

Alone: `composer install`, then `vendor/bin/phpunit`, `vendor/bin/ecs check` and `vendor/bin/phpstan analyse`.
In the Sylius Assistant stack: `castor mcp:quality`, or `bin/ci/package.sh mcp-tasks-messenger-bundle highest|lowest`.

## Traps

- **Several consumers.**
  `step_in_flight` does not say which consumer holds the step.
  A consumer that starts suspends the step another one is playing.
  Keep one consumer.
- **A worker killed after a step is done, before the next message is sent.**
  The task stays running with nothing in flight, and nothing plays its next step.
  The Doctrine transport redelivers the message after `redeliver_timeout`, and `claim()` makes it `Skipped`.
  Cancel the task.
- **Restart the worker** after a deployment that changes the bundle.
- **The grace of a stopping worker needs `pcntl`.**
  Without it, `messenger:consume` subscribes to no signal and SIGTERM kills the step at once.
- **`failed_mcp_tasks` is for reading.**
  `RunStepHandler` suspends the task on any error of a step, so a message only lands there when the worker itself broke.
  `messenger:failed:retry` on it plays nothing: `claim()` makes the step `Skipped`.
- **The consumer of `mcp_tasks` consumes nothing else.**
  The listener only acts for a worker of `mcp_tasks`: the other consumers of the server suspend no task when they start.
