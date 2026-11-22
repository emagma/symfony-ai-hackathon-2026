# MCP Agents Bundle: user guide

Background agents are programs of the MCP server that look at the shop on their own.
Nobody asks them in the chat.

This bundle is what they share:

- when they run,
- the journal of what they analysed,
- the alerts they send,
- the MCP tool that lists their findings.

It is for the staff who want to know what the agents found.
It is also for the person who decides where the alerts go.

Agents only report: they change nothing in the shop.

## What you can do

### `list_agent_reports`

- **What for**: what the background agents found, the most recent first.
- **Ask in the chat**: "Did the fraud agent flag any new customer account since yesterday?"
- **Comes back**: `{items, total}`.

Each item has:

- `agent`,
- `level` (`low`, `medium` or `high`),
- `subject` (`{type, id}`, for example a customer and its id),
- `signals` (what was observed, in short sentences),
- `explanation`,
- `createdAt`,
- `alerted` (whether an alert was sent).

| Argument | Default | Meaning |
|---|---|---|
| `agent` | all | Only this agent, for example `fraud` |
| `level` | `medium` | The lowest level to list |
| `since` | none | An ISO 8601 date, for example `2026-11-14T00:00:00Z` |
| `limit` | `30` | 1 to 50 |

`low` reports are the analyses that found nothing.
Pass `level: "low"` to see everything an agent looked at.

To see the subject itself, follow `subject.id` with the matching MCP tool (`get_customers` for a customer).

### Alerts

When an analysis reaches the alert level (`high` by default), the server sends an alert through Symfony Notifier.
A report is alerted once.
By email it is plain text:

```text
Subject: [fraud · high] customer #42

The email address of this new account cannot be a durable personal address.

- The email domain is a disposable address service (yopmail.com).
```

## Setting it up

### Environment

| Variable | Meaning |
|---|---|
| `MCP_AGENT_ALERT_EMAIL` | Who receives the alerts, comma-separated. Empty: nothing is sent, the journal only |
| `MCP_AGENTS_TRANSPORT_DSN` | The Messenger transport of the runs. Empty: a queue of the server's database |
| `MAILER_DSN` | The mail transport of the server |

### Configuration

Under `emagma_mcp_agents`:

| Key | Default | Meaning |
|---|---|---|
| `recipients` | `MCP_AGENT_ALERT_EMAIL` | Email addresses that receive the alerts |
| `sender` | `mcp-agents@localhost` | The From of the alert emails |
| `alert_from` | `high` | The lowest level that raises an alert |

```yaml
# config/packages/emagma_mcp_agents.yaml
emagma_mcp_agents:
    recipients: ['fraud@example.com', 'support@example.com']
    sender: 'assistant@example.com'
    alert_from: medium
```

### The channel

The channel of an alert is the host's `framework.notifier.channel_policy`.
Email for every importance is enough to start.
Adding a chat transport (Slack, Teams) there sends the alerts elsewhere, without touching an agent.

### The database

The journal is the table `mcp_agent_report`.
The bundle ships its migration: `doctrine:migrations:migrate` creates the table, or leaves it as it is when it exists.

### When the agents run

An agent runs when something it waits for happens.
It also runs every few minutes on its own, in case a signal was lost.
A run handles everything that is new, up to a bound of the agent's.
The next run catches up with the rest.

Three processes share the work:

| Process | Does | Without it |
|---|---|---|
| Worker of `mcp_agents` | Plays the runs and sends the alerts (transport `async`) | No agent runs, no alert leaves |
| Worker of `scheduler_mcp_agents` | The clock: asks each agent for a run every few minutes | Agents only run on their events |
| Your own worker of other queues | Not the agents' business | None |

```bash
bin/console messenger:consume mcp_agents async
bin/console messenger:consume scheduler_mcp_agents
```

- **One run at a time per agent**: a run asked while another one waits adds nothing.
- **A run asked during a run** is played after it.
- **Several workers**: a run that finds another one in progress is skipped.
  What arrived at the very end of that run waits for the next one asked, or for the schedule.
- **A run that fails is tried again** three times (after 30 s, 1 min, 2 min).
  Then it is kept aside in `failed_mcp_agents`.
- `bin/console messenger:failed:show --transport=failed_mcp_agents` reads what failed.
- `bin/console debug:scheduler` lists the agents scheduled and their next run.

## Who may use it

| MCP tool | Grant |
|---|---|
| `list_agent_reports` | `mcp:core:agent_report:read` |

Receiving the alerts needs no grant: it is the list of recipients.

## Commands

The bundle has no console command.
Each agent brings its own.

## Limits and things to know

- **A report is alerted once.** Reports below `alert_from` stay in the journal and are only seen with `list_agent_reports`.
- **No recipients, no alert.** The analyses are still recorded.
- **Several recipients**: separate them with commas in `MCP_AGENT_ALERT_EMAIL`, or list them under `recipients`.
- **Signals and explanations carry no personal data.** The subject is an id.
- **No alert arrives?** Check these, in order:
  - the level reached `alert_from`,
  - a recipient is set,
  - the worker of `async` runs, it sends the emails,
  - `MAILER_DSN`.
- **`alerted: true`** says the server queued the alert, not that it was delivered.
- **The journal is not purged** by any command of this bundle.
- **`since` not understood**: the answer is empty, with `reason: invalid_since`.
