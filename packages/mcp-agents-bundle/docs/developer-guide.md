# MCP Agents Bundle: developer guide

- **Composer name**: `emagma/mcp-agents-bundle`
- **Namespace**: `Emagma\McpAgentsBundle`
- **Config alias**: `emagma_mcp_agents`
- **Depends on**: `emagma/mcp-core-bundle`, Doctrine ORM, Symfony Messenger, Scheduler, Lock, Notifier and Mime.
- **Needs from the host**: `framework.lock` with a store every process shares (a database connection, Redis).
  The deduplication of `RunAgent` and the lock of a pass rely on it.

The bundle knows no backend, no agent and no shop event.
An agent is a service of another bundle that implements `ScheduledAgentInterface`.
It records `AgentReport`s and calls `AgentAlerter`.
What wakes an agent is a listener of that bundle: it calls `AgentRunner::trigger()`.

## Public API

What another bundle may use, and what this bundle keeps stable:

- `Schedule\ScheduledAgentInterface`: `name()`, `every()`, `run()`.
- Its autoconfiguration: the tag string `emagma_mcp_agents.agent`.
- `AgentRunner`: `trigger()`, `run()`, `has()`.
- `Entity\AgentReport` (`of()` and the getters), `Level`.
- `Store\AgentReportStoreInterface`, `Alert\AgentAlerter`.
- `Schedule\RunAgent`, the message in the transport.
  Its class name is stored serialized in the queue tables: never move it.
- `Testing\InMemoryAgentReportStore`, for the suites of bundles that bring agents.
- The constants of `EmagmaMcpAgentsBundle`: the transports and their DSNs.

The rest is `@internal`.

## How it is built

| Class | Role |
|---|---|
| `EmagmaMcpAgentsBundle` | Configuration, prepended defaults, services |
| `Entity/AgentReport` | One analysis of one thing by one agent |
| `Level` | `low`, `medium`, `high`, with `rank()` |
| `Store/AgentReportStoreInterface` | The journal's contract |
| `Store/DoctrineAgentReportStore` | Its Doctrine implementation |
| `Alert/AgentAlerter` | Sends a report through Symfony Notifier |
| `Alert/AgentAlertNotification` | The notification, plain text as an email |
| `Tool/ListAgentReports` | The MCP tool `list_agent_reports` |
| `Schedule/ScheduledAgentInterface` | What an agent is |
| `AgentRunner` | `trigger(name)` queues a pass, `run(name)` plays one |
| `Schedule/RunAgent` | "Run this agent once", on the transport `mcp_agents` |
| `Schedule/AgentsSchedule` | The schedule `mcp_agents` |
| `Schedule/RunAgentHandler` | Handles `RunAgent(name)` with `AgentRunner::run()` |
| `DependencyInjection/Compiler/ScheduledAgentsPass` | The agents by name, refuses a name twice |

The services are defined in `config/services.php`, with ids prefixed by the alias (`emagma_mcp_agents.runner`, `.alerter`, `.report_store`, `.schedule_provider`, `.run_agent_handler`).
The classes and the interface are aliased to them.
Only the `Tool/` namespace is loaded by `load()`, with autowiring.

### Running an agent

One way to ask for a pass, one way to play it, whatever asks: a listener, the schedule, a command.

- **`AgentRunner::trigger($name)`** dispatches `RunAgent($name)` with `new DeduplicateStamp('mcp_agent.queued.<name>', 3600, onlyDeduplicateInQueue: true)`.
  - Messenger's deduplication middleware takes that key before the message is sent.
  - A `RunAgent` for that agent already waiting: nothing is added.
  - The key is released when the worker receives the message, before the pass.
    An event recorded during a pass asks for another one, no trigger is lost.
  - The TTL bounds a key left behind by a worker that died.
  - An unknown name throws `\InvalidArgumentException`.
- **`AgentRunner::run($name)`** takes the lock `mcp_agent.run.<name>` (TTL 3600 s), calls the agent's `run()` and releases it.
  - A pass that does not get the lock returns null and is skipped, not queued.
    The pass that holds it reads what is new.
  - The two keys differ on purpose: one key for both would drop a trigger during a pass.
- **`RunAgentHandler`** handles `RunAgent` in the worker of `mcp_agents`.
  - An unknown name (an agent removed since the message was sent) is logged as a warning and dropped.
  - A skipped pass is a handled message, never retried.
- **`ScheduledAgentsPass`** reads each agent's static `name()` when the container is built.
  - It throws when two agents share one.
  - It registers one service locator of the agents by name: the `$agents` of the runner and of the schedule.

### The schedule

- **`AgentsSchedule`** is `#[AsSchedule('mcp_agents')]`, tagged `scheduler.schedule_provider` in `config/services.php`.
  It gives the Messenger transport `scheduler_mcp_agents`, which a process of its own consumes.
- `getSchedule()` adds a `RecurringMessage::every()` with a `RedispatchMessage(new RunAgent($name), 'mcp_agents')` for every agent whose `every()` is not null.
  The clock runs no agent: it fills `mcp_agents` like a trigger does.
- **Stateful**: on the cache pool `emagma_mcp_agents.schedule` (prepended, adapter `cache.adapter.doctrine_dbal`), so the last run survives a restart.
  `processOnlyLastMissedRun(true)`: after a stop, one run, not every missed one.
- **Locked**: on `emagma_mcp_agents.schedule` (`AgentsSchedule::LOCK`) of the host's lock factory: two processes of the schedule emit each tick once.
- The scheduled `RunAgent` carries no `DeduplicateStamp`.
  A recurring message is one instance reused at every tick, and its key would keep the token of its first lock.

### Transports

| Transport | DSN | Retry | On failure |
|---|---|---|---|
| `mcp_agents` | `%env(default:emagma_mcp_agents.transport_dsn:MCP_AGENTS_TRANSPORT_DSN)%` | 3, delay 30 s, x2 | `failed_mcp_agents` |
| `failed_mcp_agents` | `doctrine://default?queue_name=failed_mcp_agents` | none | none |

- **Retried on purpose**: a pass replayed records nothing twice (`uniq_mcp_agent_report_source`).
- **`MCP_AGENTS_TRANSPORT_DSN` empty or not set**: the parameter `emagma_mcp_agents.transport_dsn`, which is `doctrine://default?queue_name=mcp_agents` unless the host sets it.
- **`RunAgent` is routed to `mcp_agents`.**
  The schedule's messages arrive through `RedispatchMessage`, which names the same transport.

### What an agent's run does with the journal

1. `AgentReportStoreInterface::lastSequence($agent)` gives the highest `source_sequence` this agent recorded, 0 when it never ran.
   The agent reads what comes after.
2. For each thing analysed, `AgentReport::of(...)` then `record($report)`.
   It returns false, and writes nothing, when this agent already has a report for that `source`.
3. `AgentAlerter::alert($report)` returns false when:
   - there is no recipient,
   - the level is below the configured one,
   - `getNotifiedAt()` is set.

   Otherwise it sends an `AgentAlertNotification` to one `Recipient` per address, then calls `markNotified()`.
   The subject is `[<agent> · <level>] <subjectType> #<subjectId>`.
   The content is the explanation, then one `- ` line per signal.
4. `AgentReportStoreInterface::save()` once at the end persists the `notified_at` set in step 3.

## Extension points

### An agent

Implement `ScheduledAgentInterface` in a service of your bundle (autoconfigured).
The runner and the schedule find it by its name.
A command that runs it by hand goes through `AgentRunner::run()` to take the agent's lock.

```php
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;

final readonly class StockAgent implements ScheduledAgentInterface
{
    public function __construct(
        private ?string $every = '15 minutes',   // null: by hand only
    ) {
    }

    /** Its reports' `agent`: never change it, the cursor of the agent is read from them. */
    public static function name(): string
    {
        return 'stock';
    }

    public function every(): ?string
    {
        return $this->every;
    }

    /** @return array{analysed: int} */
    public function run(): array
    {
        // from $reports->lastSequence('stock'), batch after batch up to a bound of its own:
        // read what came after the cursor, record, alert, save, move the cursor on
        return ['analysed' => 0];
    }
}
```

### Recording and alerting from an agent

```php
use Emagma\McpAgentsBundle\Alert\AgentAlerter;
use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use Emagma\McpAgentsBundle\Store\AgentReportStoreInterface;
use Psr\Clock\ClockInterface;

final readonly class StockAnalysis
{
    public function __construct(
        private AgentReportStoreInterface $reports,
        private AgentAlerter $alerter,
        private ClockInterface $clock,
    ) {
    }

    public function analyse(string $eventId, int $sequence, string $variantCode): void
    {
        $report = AgentReport::of(
            'stock',
            $eventId,                 // source: unique per agent
            $sequence,                // sourceSequence: where the agent stopped
            'product_variant',        // subjectType
            $variantCode,             // subjectId
            Level::High,
            ['The variant is out of stock.'],
            'This variant can no longer be ordered.',
            $this->clock->now(),
        );

        if ($this->reports->record($report)) {
            $this->alerter->alert($report);
        }
        $this->reports->save();
    }
}
```

### Waking an agent on an event

The bundle of the agent binds what wakes it.
A listener calls `AgentRunner::trigger()`, never `run()`: the pass belongs to the worker.

```php
final readonly class OutOfStockListener
{
    public function __construct(
        private AgentRunner $runner,
    ) {
    }

    public function __invoke(StockEvent $event): void
    {
        $this->runner->trigger('stock');   // one RunAgent waiting at most
    }
}
```

A command that plays a pass by hand:

```php
$summary = $this->runner->run('stock');   // null: a pass is running, nothing was done
```

### The journal

`AgentReportStoreInterface` is aliased to `DoctrineAgentReportStore`.

```php
public function record(AgentReport $report): bool;
public function save(): void;
public function lastSequence(string $agent): int;
public function find(?string $agent = null, ?Level $atLeast = null, ?\DateTimeImmutable $since = null, int $limit = 30): array;
public function purgeBefore(\DateTimeImmutable $before): int;
```

In a test, `Testing\InMemoryAgentReportStore` replaces it.
Its `$reports` property is public, for assertions.

### The alert channel

Not an extension point of the bundle.
`AgentAlerter` sends to `NotifierInterface`.
The channel per importance is the host's `framework.notifier.channel_policy`.

## Configuration reference

| Key | Default | Notes |
|---|---|---|
| `recipients` | `['%env(default::MCP_AGENT_ALERT_EMAIL)%']` | A string given here is split on commas |
| `sender` | `mcp-agents@localhost` | Not empty |
| `alert_from` | `high` | One of `low`, `medium`, `high` |

They become the parameters `emagma_mcp_agents.recipients`, `.sender` and `.alert_from`, which the alerter takes.

The bundle prepends:

| Target | What |
|---|---|
| `emagma_mcp_core` | The permission domain `core` for `Emagma\McpAgentsBundle\` |
| `emagma_mcp_core` server | The tool namespace `Emagma\McpAgentsBundle\Tool\` |
| `framework.messenger` | The transports `mcp_agents` and `failed_mcp_agents`, `RunAgent` routed to `mcp_agents` |
| `framework.cache` | The pool `emagma_mcp_agents.schedule`, adapter `cache.adapter.doctrine_dbal` |
| parameter | `emagma_mcp_agents.transport_dsn`, unless the host sets it |
| `doctrine.orm` | The attribute mapping of `src/Entity` |
| `doctrine_migrations` | The path of its migrations |

Permission of the MCP tool: `#[Permission(resource: 'agent_report', action: Action::Read)]`, hence the grant `mcp:core:agent_report:read`.

## Data

Table `mcp_agent_report`, entity `Entity\AgentReport`.

| Column | Holds |
|---|---|
| `agent` | The agent's name, for example `fraud` |
| `source` | What was analysed (the id of the event) |
| `source_sequence` | The sequence of that event |
| `subject_type`, `subject_id` | What the report is about |
| `level` | `low`, `medium`, `high` |
| `signals` | JSON list of short sentences |
| `explanation` | Text |
| `created_at`, `notified_at` | When recorded, when alerted (nullable) |

Indexes: unique `uniq_mcp_agent_report_source` on (`agent`, `source`), and `idx_mcp_agent_report_agent` on (`agent`, `created_at`).

The mapping is by attributes.
The entity is not final, so that Doctrine can build its proxies.
The fields set once are promoted and read-only.

### The migration

`migrations/Version20261122000000.php` creates the table on MySQL, MariaDB and SQLite.
It does nothing when the table exists.
A host that created the table with a migration of its own runs it as a no-op, recorded as done.
On another platform, write the migration yourself: `doctrine:schema:update --dump-sql` gives it.

Its rollback keeps the table: dropping the journal is a decision, not a rollback.

## Tests and quality

The tests run on their own, on the lowest and the highest dependencies:

```bash
bin/ci/package.sh mcp-agents-bundle highest
bin/ci/package.sh mcp-agents-bundle lowest
```

- **`tests/Unit`**: the alerter, the runner over a serializing in-memory transport and the deduplication middleware, the schedule, the compiler pass, the prepended configuration, the tool.
- **`tests/Integration`**: a minimal kernel on SQLite and in-memory transports.
  - The wiring: agents found by autoconfiguration, the transport, the schedule, the tool on the server.
  - The journal on a real database.
  - The migration against the mapping, on a fresh database and on one that has the table.

## Traps

- **Call `save()` after `alert()`.**
  `alert()` only sets `notifiedAt` on the entity: without the flush the report would be alerted again.
- **Record even the analyses that found nothing** (`Level::Low`).
  `lastSequence()` is computed from the reports, so an unrecorded item is read again at every run.
- **`MCP_AGENT_ALERT_EMAIL` is split twice, on purpose.**
  The normalization of `recipients` splits a string written in the configuration.
  The default is a list holding one environment placeholder, only known at runtime: `AgentAlerter` splits each entry on commas again.
- **`purgeBefore()` has no caller**: the bundle ships no purge command.
- **`record()` checks then inserts**: two workers analysing the same source at once rely on the unique index.
- **Signals must hold no personal data.**
  They are stored, listed by `list_agent_reports` and sent by email.
- **`name()` is static** so that the pass can read it when the container is built.
  It is also the `agent` column of the reports: renaming an agent restarts its cursor at 0.
- **`every()` is read when the process of the schedule builds it.**
  A change needs that process restarted, and the schedule instantiates every agent.
- **An agent with `every()` null is not scheduled.**
  The process of the schedule still consumes `scheduler_mcp_agents`: an empty schedule, not a missing one.
  The agent still runs when triggered.
- **The deduplication needs `framework.lock`.**
  Without it Messenger leaves its middleware out: every trigger queues a `RunAgent`, and only the lock of a pass keeps two from overlapping.
- **A lock store per process (`flock`, `semaphore`) is no lock here.**
  The web server, the workers and the scheduler are different processes.
- **A worker that dies holds its keys until their TTL** (an hour).
  The queued key lets no trigger through, the pass key skips every pass.
  The agent resumes after the TTL, nothing is lost: the journal is read from the agent's cursor.
- **`alerted: true` means queued.**
  Route Mailer's and Notifier's messages to an async transport, and a worker sends them.
  An alert is marked notified when it is handed to Notifier, not when it is delivered.
- **`AgentAlertNotification` renders no template** on purpose.
  Other channels get the subject and the content as any notification.
