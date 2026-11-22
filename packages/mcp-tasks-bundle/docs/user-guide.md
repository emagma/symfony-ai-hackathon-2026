# MCP Tasks Bundle: user guide

A background task is a long treatment on your system: translate the whole catalogue, reorganise the categories, rewrite every description.
The assistant prepares it with you in the chat, you validate it, and the server then runs it without you.

This guide is for the people who launch and follow tasks, and for the person who installs the server.

A task only does what you could do yourself in the chat.
It calls the same MCP Tools, with your rights, checked at each call.

## How a task goes

1. **You ask** in the chat for something long.
   The assistant offers a task.
2. **It asks what it needs**: which channel, which language, what must not move.
   It counts what is concerned and writes a summary you confirm.
3. **It writes the task** and shows you its reading: what the task will do, step by step, in plain language.
   The server wrote that reading from the task itself, never from the conversation.
4. **You launch it.**
   The first time, the assistant gives you a link.
   Open it once: it lets the server act for you while you are away.
5. **The task runs in the background.**
   You can close the chat.
6. **You are told by email** when it is over or when it stopped.
   In any conversation, ask where it stands.

## What you can do

| MCP Tool | Purpose |
|---|---|
| `create_task` | Prepare a task, nothing runs yet |
| `start_task` | Launch a prepared task |
| `list_tasks` | Find your tasks again |
| `get_task` | See where one stands, its calls or its scripts |
| `cancel_task` | Stop a task |
| `revoke_task_authorization` | Take back the right the server has to act for you |
| `llm_transform` | Turn one content into a typed value with a model |

### `create_task`

- **For**: preparing a task.
- **Ask in the chat**: "Translate the whole catalogue into Italian."
- **Comes back**: a draft with its `reading` and its `manifest`, the list of MCP Tools the task may call.
- **Drafts**: a new draft replaces your previous one.
- **Renamed tools**: when the model prefixed a tool name, the server corrects it and answers the real names in `renamed`.

### `start_task`

- **For**: launching a draft.
- **Confirmation**: you confirm once, for everything the task will write.
- **Comes back**: the task, `queued`.
- **First time**: `authorization_required`, with a `url` to open once.
  It comes before the confirmation: you are not asked to agree to a launch that cannot happen yet.

### `list_tasks` and `get_task`

- **For**: finding your tasks again and seeing where one stands.
- **Ask in the chat**: "Where is my translation task?"
- **Comes back**: the status, the step being played out of how many, the number of tool calls made and the last one.
- **`detail: "calls"`**: the last calls and what each answered.
- **`detail: "scripts"`**: the scripts of the task.

| Status | Meaning |
|---|---|
| `draft` | Prepared, not launched |
| `queued` | Launched, waiting for the worker |
| `running` | A step is being played |
| `suspended` | Stopped on an error |
| `done` | Over |
| `cancelled` | Stopped on your request |

### `cancel_task`

- **For**: stopping a task at its next tool call.
- **What stays**: what it already wrote stays written, nothing is undone.
- **Never deleted**: cancelled, suspended or over, a task stays in `list_tasks` with its scripts and the journal of its calls.
- **Only a draft** that was never launched gives way to your next draft.

### `revoke_task_authorization`

- **For**: taking back what lets the server act for you.
- **Effect**: your running tasks are suspended.

### `llm_transform`

- **For**: having a model of the server turn one content into a typed value, such as a translation or one category among a fixed list.
- **Made for the tasks**, usable in the chat.

## Who may use it

| MCP Tool | Grant |
|---|---|
| `list_tasks`, `get_task` | `mcp:core:task:read` |
| `create_task`, `start_task`, `cancel_task`, `revoke_task_authorization` | `mcp:core:task:write` |
| `llm_transform` | `mcp:core:llm:read` |

A task also needs, at each call, the grant of the MCP Tool it calls.
A task cannot do what its owner may not do.

## Setting it up

### Environment

The delegation reads five variables, all empty until you set them.

| Variable | Meaning |
|---|---|
| `MCP_TASKS_AUTHORIZE_URL` | The authorization endpoint of the identity provider, as a browser reaches it |
| `MCP_TASKS_TOKEN_URL` | Its token endpoint, as the server reaches it |
| `MCP_TASKS_CLIENT_ID` | The OAuth client of the tasks, with offline access |
| `MCP_TASKS_CLIENT_SECRET` | Its secret |
| `MCP_TASKS_REDIRECT_URI` | The public URL of `/tasks/authorize/callback` on the server |

The model that reads the scripts and serves `llm_transform` is a Symfony AI platform of your application.
By default the bundle uses the service `ai.platform.anthropic`.

### Configuration

Under `emagma_mcp_tasks`:

| Key | Default | Meaning |
|---|---|---|
| `orchestrator` | none | The service that plays the steps |
| `directory` | `%kernel.project_dir%/var/tasks` | Where the scripts are stored, shared by the server and the worker |
| `platform` | `ai.platform.anthropic` | The Symfony AI platform |
| `model` | `claude-haiku-4-5` | The model of the readings and of `llm_transform` |
| `max_output_tokens` | `4000` | Its output budget per call |
| `sender` | `mcp-tasks@localhost` | The From of the emails |
| `limits.step_timeout` | `14400` | Seconds one step may last |
| `limits.max_calls` | `100000` | Tool calls one task may make |
| `limits.memory_limit` | `128M` | Memory of the process of a step |
| `limits.max_input_length` | `20000` | Characters `llm_transform` accepts |
| `linter.enabled` | `false` | Lint the files of a task in place before they are read |
| `linter.path` | none | The linter's binary, required when enabled, checked at start |
| `linter.command` | null | Its arguments, with `{task}`; null means ECS with the bundle's rules |
| `delegation.token_handler` | required | The access-token handler of the firewall that guards `/_mcp` |

```yaml
# config/packages/emagma_mcp_tasks.yaml
emagma_mcp_tasks:
    delegation:
        token_handler: App\Security\MyAccessTokenHandler
```

### The route

The identity provider sends the user back to `/tasks/authorize/callback`.
Import the route of the bundle:

```yaml
# config/routes.yaml
emagma_mcp_tasks:
    resource: '@EmagmaMcpTasksBundle/config/routes.yaml'
```

### The tables

The bundle ships a Doctrine migration that creates its three tables, `mcp_task`, `mcp_task_call` and `mcp_task_authorization`.
It runs with `doctrine:migrations:migrate` when DoctrineMigrationsBundle is installed.
A table that already exists is left alone.

### The orchestrator

The bundle does not play the steps itself.
An orchestrator decides which step runs, and when.

- **Messenger**: `emagma/mcp-tasks-messenger-bundle`, registered beside this one, plays the steps as messages of a queue.
- **Another one**: a workflow engine or a job runner plays each step through the console.
- **None**: the container is not built.

Without an orchestrator, the error reads `no orchestrator: register emagma/mcp-tasks-messenger-bundle or set emagma_mcp_tasks.orchestrator`.

For another orchestrator, see the developer guide.

## Limits and things to know

- **A proof of concept, not for production.**
  The scripts run in a PHP process restricted by PHP itself, under the same system user as the server.
  That is enough for a demonstration, not against a flaw of PHP.
  For production, the scripts must run on a task server: a protected service of its own (see the developer guide).
- **Functions and classes of PHP are written with a leading backslash**, as in `\sprintf()` and `new \RuntimeException()`.
  With the linter, the server adds the missing ones.
  Without it, the task is refused with every occurrence listed.
- **What a script may not write** is refused when the task is created, with the reason: a function or a class outside the lists, a class of its own, a call whose target is computed, a function given by its name.
  Scripts run in strict mode.
- **A task stops at its first error** and does not resume.
  What it wrote before stays written.
  Ask the assistant what was done (`get_task`), then have it prepare a new task for what remains.
- **No percentage.**
  The server knows which step is being played, not what remains inside it.
  Several minutes without a tool call mean the task is stuck.
- **A right you lose stops the task** at the call that needed it.
- **Nothing is undone** by cancelling.
  Updates can be reverted one by one when the audit bundle is installed.
- **A task cannot launch a task**, nor do anything outside the MCP Tools of its manifest.
- **The link of `start_task`** is yours and lasts fifteen minutes.
  Opened with another account, it records nothing.
- **A worker stopped in the middle of a step** suspends the task with the reason `interrupted`, and you are told by email.
  The last call may have been written without the task knowing: `get_task` with `detail: "calls"` marks it `uncertain`.
  A worker stopped between two steps loses nothing: the next step starts when it is back.
- **No email arrives?**
  The address is the one of your account at the identity provider.
  The channel is the Symfony Notifier of your application.
