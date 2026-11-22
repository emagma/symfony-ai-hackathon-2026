# MCP Sylius Events Bundle: developer guide

- **Composer name**: `emagma/mcp-sylius-events-bundle`
- **Namespace**: `Emagma\McpSyliusEventsBundle`
- **Config alias**: `emagma_mcp_sylius_events` (no configuration tree)
- **Depends on**: Doctrine ORM, `symfony/framework-bundle`, `symfony/webhook`, `symfony/remote-event`, `psr/clock`, `psr/event-dispatcher`.
  No other bundle of the monorepo, not even the core.
- **Needs from the host**: Symfony Webhook's route under `/webhook`, and the variable `MCP_EVENT_WEBHOOK_SECRET`.

It is the receiving half of `emagma/sylius-mcp-event-plugin`, which runs in the shop.
It knows no agent: a bundle that reacts to an event listens to `ShopEventRecorded`.

## Public API

What another bundle may use, and what this bundle keeps stable:

- `Event\ShopEventRecorded`: `name`, `subjectId`, `sequence`.
- `Store\ShopEventStoreInterface`: `record()` and `after()`.
- `Entity\ShopEvent` and its getters.
- `Webhook\ShopEventConsumer::WEBHOOK`: the webhook is named `sylius`, so its route is `/webhook/sylius`.
- `Testing\InMemoryShopEventStore`, for the suites of bundles that read the journal.
- The table `mcp_shop_event` and its migration.

The rest is `@internal`.

## How it is built

| Class | Role |
|---|---|
| `EmagmaMcpSyliusEventsBundle` | Prepended defaults and services |
| `Webhook/ShopEventConsumer` | Remote event consumer `sylius`: writes the journal, dispatches `ShopEventRecorded` |
| `Event/ShopEventRecorded` | Dispatched once an event of the shop is recorded |
| `Entity/ShopEvent` | One row of the journal |
| `Store/ShopEventStoreInterface` | `record()` and `after()` |
| `Store/DoctrineShopEventStore` | Its Doctrine implementation |

The flow of one webhook:

1. The shop posts to `/webhook/sylius`, the route of Symfony Webhook.
2. `emagma_mcp_sylius_events.request_parser` (Symfony's own `RequestParser`) checks the signature with `MCP_EVENT_WEBHOOK_SECRET` and builds a `RemoteEvent`.
3. `ShopEventConsumer::consume()` (tag `remote_event.consumer`, `consumer: sylius`) records a `ShopEvent`.
   The subject id is `payload['id']` when it is a string or an integer, null otherwise.
4. `record()` returns false, without writing, when the event id is already known.
5. When it returned true, the consumer dispatches `ShopEventRecorded` through the event dispatcher.
   This is synchronous, inside the webhook's request.
   A listener that throws fails the webhook, which the shop delivers again.
   The event is then known, and dispatched no more.

## Extension points

### Read the journal

```php
use Emagma\McpSyliusEventsBundle\Store\ShopEventStoreInterface;

final readonly class NewCustomersReader
{
    public function __construct(
        private ShopEventStoreInterface $events,
    ) {
    }

    public function read(int $lastSequence): int
    {
        foreach ($this->events->after('customer.created', $lastSequence, 50) as $event) {
            $customerId = $event->getSubjectId();   // ?string
            // fetch the customer through the Admin API
            $lastSequence = $event->getSequence();
        }

        return $lastSequence; // keep it: the bundle does not remember where a reader stopped
    }
}
```

`after()` returns the events of one name above a sequence, oldest first.
The sequence is the auto-increment id of the row.

### React to an event

```php
use Emagma\McpSyliusEventsBundle\Event\ShopEventRecorded;

final readonly class NewOrderListener
{
    public function __invoke(ShopEventRecorded $event): void
    {
        if ('order.placed' === $event->name) {
            // ask for the work, do not do it here
        }
    }
}
```

Register it with `#[AsEventListener]`, or the tag `kernel.event_listener` on `ShopEventRecorded`.
Keep it short and make it not throw: it runs in the shop's request.

A new kind of event needs no change here.
The consumer records whatever name the shop sends.
The new listener is added in the shop's plugin.

## What the bundle prepends

- **`framework.webhook.routing.sylius`**: `service` is the request parser, `secret` is `%env(MCP_EVENT_WEBHOOK_SECRET)%`.
- **`doctrine.orm.mappings.EmagmaMcpSyliusEventsBundle`**: the attribute mapping of `src/Entity`.
- **`doctrine_migrations.migrations_paths`**: its migrations, when the migrations bundle is there.

## Data

Table `mcp_shop_event`, entity `Entity\ShopEvent`:

| Column | Holds |
|---|---|
| `id` | Auto-increment, the sequence readers keep |
| `event_id` | The id the shop gave the event, unique |
| `name` | For example `customer.created` |
| `subject_id` | The id of what the event is about, nullable |
| `payload` | The JSON payload as received |
| `received_at` | When the server received it |

Indexes: unique `uniq_mcp_shop_event_event_id` on `event_id`, `idx_mcp_shop_event_name` on (`name`, `id`).

The fields are set once by the constructor and are read-only.
The entity is not final: Doctrine builds its proxies by inheritance.

### Migration

`Migrations\Version20261122000000` creates the table as the mapping describes it, on MySQL, MariaDB and SQLite.
It does nothing when the table exists, and is recorded as done: an application that created the table with its own migration runs it as a no-op.
Its `down()` keeps the table.
On another platform, write the migration yourself (`doctrine:schema:update --dump-sql`).

## Tests and quality

- **`tests/Unit`**: the consumer (a journal entry with its subject, one record and one dispatch per event, a reader that resumes after its last sequence) and the bundle's prepended defaults.
- **`tests/Integration`**: a minimal kernel on SQLite with Symfony Webhook.
  It posts signed webhooks to `/webhook/sylius`, and checks the migration against the mapping.

Alone, from the package: `composer install`, then `vendor/bin/phpunit`, `vendor/bin/ecs check`, `vendor/bin/phpstan analyse`.

## Traps

- **The endpoint is outside any firewall of yours.** The signature is its only guard.
- **The payload carries ids, never data**, by design of the plugin.
  Do not read names or emails from `getPayload()`: fetch the subject through the Admin API.
- **The bundle keeps no cursor.** Each reader stores its own last sequence.
- **`record()` checks, then inserts.** Two simultaneous deliveries of one event rely on the unique index: the second insert fails as an exception instead of returning false.
- **No purge.** Nothing removes rows from `mcp_shop_event`.
- **The signature check is Symfony's.** Only the integration tests exercise it, through the prepended route.
