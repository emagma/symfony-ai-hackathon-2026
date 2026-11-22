# MCP Tasks Messenger Bundle: user guide

This bundle plays the steps of the background tasks with Symfony Messenger.
It sits beside `emagma/mcp-tasks-bundle`, which writes the tasks and checks them.

It brings no MCP Tool of its own.
This guide is for the person who installs and runs the server.

## What changes in the chat

Nothing appears in the chat.

With this bundle registered:

- a task launched with `start_task` is played in the background by a worker, step after step,
- `get_task` shows where it stands,
- the owner is told by email when it is over or stopped.

## Set it up

There is no configuration key.
Three things are needed.

1. **Register the bundle** beside the tasks bundle.
   It names itself as the orchestrator of the tasks.
   Another orchestrator replaces it: unregister this bundle and set `emagma_mcp_tasks.orchestrator`.

2. **Run one worker for the queue `mcp_tasks`, and only for it.**
   The other queues of the server keep their own consumers.
   A step of several hours then delays no agent and no email.

   ```bash
   php bin/console messenger:consume mcp_tasks --time-limit=3600 --memory-limit=256M
   ```

   Messenger finishes the message in flight when the worker is asked to stop (SIGTERM).
   Give the worker time to do it: ten minutes of grace on a container, for example.
   A step shorter than that survives a deployment.

3. **Name the queue**, only if it is not the server's database.

   | Variable | Meaning |
   | --- | --- |
   | `MCP_TASKS_TRANSPORT_DSN` | The transport of the queue |

   Its default is `doctrine://default?queue_name=mcp_tasks&redeliver_timeout=172800`.
   A step may last hours: the transport must not hand its message to another consumer meanwhile.
   That is the role of `redeliver_timeout`, in seconds.

## Who may use it

Nobody in particular: the bundle declares no grant.
The grants are those of the tasks bundle.

## Commands

None of its own.
The worker is Messenger's `messenger:consume`.

## Limits and things to know

- **A message is never retried.**
  A step that wrote must not write twice.
- **`failed_mcp_tasks` keeps what broke the worker itself.**
  - A step that fails suspends its task, and its message is done.
  - Only an error outside the step sends the message to this queue.
  - Read it with `messenger:failed:show --transport=failed_mcp_tasks`, never replay it: a replay plays nothing, the step answers `Skipped`.
- **One consumer plays one step at a time.**
  A step of several hours keeps its consumer busy.
- **A worker that stops between two steps loses nothing.**
  The message of the next step waits in the queue.
  `--time-limit` is harmless.
- **A worker that stops in the middle of a step** (longer than its grace, or a crash) leaves the task suspended.
  When a worker starts again, the task gets the reason `interrupted` and its owner is told by email.
  It is never played again.
- **Run one consumer of `mcp_tasks`.**
  A consumer that starts suspends every task with a step in flight, the one another consumer is playing included.
