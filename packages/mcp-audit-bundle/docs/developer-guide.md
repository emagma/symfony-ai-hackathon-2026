# MCP Audit Bundle: developer guide

- **Composer name**: `emagma/mcp-audit-bundle`
- **Namespace**: `Emagma\McpAuditBundle\`
- **Bundle class**: `Emagma\McpAuditBundle\EmagmaMcpAuditBundle`, an `AbstractBundle` (`configure()`, `prependExtension()`, `loadExtension()`, `build()`); there is no Extension nor Configuration class.
- **Config alias**: `emagma_mcp_audit`
- **Depends on**: `emagma/mcp-core-bundle` `^0.1`, Doctrine ORM and DoctrineBundle, `mcp/sdk`.
- **Suggested**: `doctrine/doctrine-migrations-bundle`, to create the table with the bundle's migration.
- **Depended on by**: the application only; the bundles that write record through the core's `WriteJournalInterface` and do not require this one.
- **In the Sylius Assistant stack**: the layer is `Audit: [Core]` in `apps/mcp-server/deptrac.yaml`.

Classes are `final` and `@internal`, except the extension points: `AuditRepositoryInterface`, the entity `AuditEntry` (not final, Doctrine may proxy it) and the bundle class.
A backend plugs in through the core's interfaces (`WriteJournalInterface`, `RevertTargetInterface`, `SubjectReferencesInterface`, `ActorProviderInterface`).

## How it is built

| Class | Role |
|---|---|
| `src/EmagmaMcpAuditBundle.php` | Configuration, prepended defaults, parameters, compiler pass |
| `src/Entity/AuditEntry.php` | One row per write call or per person revealed |
| `src/AuditWriter.php` | Implements `WriteJournalInterface`: redacts, persists, never throws |
| `src/Repository/AuditRepositoryInterface.php` | The queries the tools and commands read through |
| `src/Repository/AuditRepository.php` | The implementation, built on the manager registry |
| `src/AuditScope.php` | Whose entries the caller reaches; the grant of the recording tool |
| `src/Tool/SearchMcpEdits.php` | `search_mcp_edits` |
| `src/Tool/GetMcpEdits.php` | `get_mcp_edits` |
| `src/Tool/RevertMcpEdit.php` | `revert_mcp_edit` |
| `src/Tool/ForgetAuditPersonalData.php` | `forget_audit_personal_data` |
| `src/Revert/RevertPlan.php` | The value object of one planned revert |
| `src/Revert/NullRevertTarget.php` | Default `RevertTargetInterface` without a backend |
| `src/Forget/PersonalDataEraser.php` | The erasure, shared by the tool and the command |
| `src/Forget/NoSubjectReferences.php` | Default `SubjectReferencesInterface` without a backend |
| `src/Output/McpEditSummaryOutput.php`, `McpEditOutput.php` | The lean and the full shape |
| `src/DiffSummarizer.php` | The one-line `summary` of a diff |
| `src/Command/PurgeAuditCommand.php` | `mcp:audit:purge` |
| `src/Command/ForgetPersonalDataCommand.php` | `mcp:audit:forget-personal-data` |
| `src/EventListener/AuditTableListener.php` | Applies `emagma_mcp_audit.table` to the mapping |
| `src/EventListener/AuditEntryLockListener.php` | Refuses any update of a forgotten entry |
| `src/DependencyInjection/Compiler/DefaultRevertTargetPass.php` | Registers the defaults when no backend aliased the interfaces |
| `config/services.php` | The explicit service definitions |
| `migrations/Version20261122000000.php` | Creates the table |

### Services

Services are explicit in `config/services.php`, with ids prefixed by the alias:

- `emagma_mcp_audit.repository`
- `emagma_mcp_audit.writer`
- `emagma_mcp_audit.scope`
- `emagma_mcp_audit.personal_data_eraser`
- `emagma_mcp_audit.listener.audit_table`
- `emagma_mcp_audit.listener.audit_entry_lock`
- `emagma_mcp_audit.command.purge`
- `emagma_mcp_audit.command.forget_personal_data`

Aliases make them injectable:

- `WriteJournalInterface` and `AuditWriter` point to the writer.
- `AuditRepositoryInterface` points to the repository.
- `AuditScope` and `PersonalDataEraser` point to their service.

Only `src/Tool/` is loaded by namespace with autowiring: it is the unit `symfony/mcp-bundle` discovers.

`DefaultRevertTargetPass` registers `emagma_mcp_audit.revert_target.null` and `emagma_mcp_audit.subject_references.none`, and aliases the core's interfaces to them, only when no bundle did.
It is a compiler pass, so the order of the bundles does not matter.

### Recording a write

A write tool of any bundle calls `WriteJournalInterface::record()` (or `stage()` per item then `flush()` for a batch).
The container aliases the interface to `AuditWriter`, which:

1. redacts `beforeState` and `afterState` with `SensitiveDataRedactor::redact()`, the diff with `redactDiff()`, and the target code with `redactText()` (an email becomes the marker: a tool gives an id);
2. builds an `AuditEntry` with the session id of the `RequestContext`, the actor of `ActorProviderInterface::actor()`, the batch id, and `revertible`, false when the core's `ToolClassMap::isIrreversible()` says so of the tool name (`#[Irreversible]`, or a `delete` permission);
3. persists and flushes.

A `\Throwable` from step 3 is caught: the entry, already redacted, is logged at critical level on the `emagma_mcp_audit` channel ("MCP audit entry could not be persisted: recorded here instead.").
The backend has already changed, so the tool call must not fail.

`recordEntry()` and `stageEntry()` are the same with `$reverts` and `$locked` (born forgotten: the trace of an erasure) and return the entry.
`markReverted()` links the original to its revert.

### Scopes: `AuditScope`

- `actor(Action)`: `null` when the caller holds `mcp:<domain>:audit_all:<action>` (every entry), else the caller's actor (their own entries), else `false` (no identity: no entry).
- The domain is the bundle's (`ToolPermissionResolver::domainOf()`), `core` here.
- The tools pass that actor to `AuditRepositoryInterface::findByIds()`, `findByBatch()` and `paginate(['actor' => ...])`, so `total` counts what the caller may see.
- `revert_mcp_edit` checks `reaches($entry, Action::Write)` on a single entry.
- `mayCall($tool)`: the grants of the tool that recorded the entry, its class found by `ToolClassMap::classOf()`; a name the server no longer exposes is refused, but for an explicit allow-all.
- The tools declare the widening with `#[WidenedBy(resource: AuditScope::ALL, action: ...)]`, which `mcp:permissions` lists, so an identity provider can mint `audit_all:read` / `:write` from it.

### Reverting: `RevertMcpEdit`

1. `id` xor `batchId`, else `id_or_batch_id`. An entry, or a batch, out of the caller's scope is `not_found`.
2. `plan()` per entry, which checks in this order:
   - the recording tool's grant (`forbidden`);
   - already reverted;
   - forgotten (`redacted_snapshot`);
   - not `revertible`, or empty `beforeState` (`nothing_to_restore`);
   - a snapshot holding the redaction marker (`redacted_snapshot`);
   - `RevertTargetInterface::locate()` returning null (`unrevertable_snapshot`);
   - `read()` throwing `RevertRefusedException` (`target_not_found`, or the exception's `failure`);
   - an empty `diff()` (`already_at_target_state`).
   Otherwise the result is a `RevertPlan`.
3. `confirmWrite()` (the core's `WriteConfirmationTrait`), once; for a batch, one preview joining every plan.
4. `apply()`: `write()`, then a new entry is recorded with `reverts` set, before the check, because an incomplete revert still changed the backend.
5. The resource returned by the backend is diffed against the target again: a remainder answers `revert_incomplete` and the original is not marked, so a later attempt is not blocked.
6. Otherwise `markReverted()`.

A batch is reverted the latest entry first (`AuditRepositoryInterface::findByBatch()` orders by id descending).

### Lifetime

- `PurgeAuditCommand`: cutoff = now minus `--older-than` or `emagma_mcp_audit.retention`; `countBefore()` under `--dry-run`, else `purgeBefore()` (one DQL delete).
- `PersonalDataEraser::concerning()`: `findConcerning($type, $id, $suffixes)` returns the entries whose target is the subject, plus those whose snapshots hold, at any depth, a string ending with one of the suffixes of `SubjectReferencesInterface::suffixes()`.
- `PersonalDataEraser::forget()`: skips the forgotten ones, stages the trace entry (`forget_audit_personal_data`, same target, `after: {forgotten: n}`, locked), scrubs each with `AuditEntry::forget($marker, $trace)`, flushes once.
- It throws, unlike the writer: nothing has changed anywhere if the flush fails.
- The lock: `forget()` is a no-op on a forgotten entry, `setRevertedBy()` throws, and `AuditEntryLockListener` (`preUpdate`) refuses any update but the one that sets `forgottenAt`.
- The purge deletes in DQL, without the listener.

## Extension points

The bundle has one interface of its own, `AuditRepositoryInterface`, and plugs into the contracts of the core (`Emagma\McpCoreBundle\Journal`), which a backend bundle implements and aliases.

### Read the audit: `AuditRepositoryInterface`

- The tools and the commands read through it: `find()`, `findByIds()`, `findByBatch()`, `findConcerning()`, `paginate()`, `countBefore()`, `purgeBefore()`.
- To change the storage, define a service implementing it and alias the interface to it.
- A test mocks it instead of building a Doctrine stack.

### Tell where a snapshot is restored: `RevertTargetInterface`

A backend bundle implements it and aliases the core's interface to its service.
In the Sylius Assistant stack, it is `Emagma\McpSyliusBundle\AdminApi\AdminApiRevertTarget`.

```php
use Emagma\McpCoreBundle\Exception\RevertRefusedException;
use Emagma\McpCoreBundle\Journal\RevertTargetInterface;

final class ThingRevertTarget implements RevertTargetInterface
{
    public function locate(array $snapshot): ?string
    {
        return \is_string($snapshot['@id'] ?? null) ? $snapshot['@id'] : null;
    }

    public function read(string $address): array
    {
        // ... fetch; when it is gone:
        throw new RevertRefusedException(true, ['reason' => 'not_found']);
    }

    public function write(string $address, array $snapshot): array
    {
        // ... write, return the resource as the backend holds it afterwards;
        // on a refusal: throw new RevertRefusedException(false, ['reason' => 'validation_error', ...]);
        return $snapshot;
    }

    public function diff(array $current, array $snapshot): array
    {
        $diff = [];
        foreach ($snapshot as $field => $value) {
            if (($current[$field] ?? null) !== $value) {
                $diff[$field] = ['before' => $current[$field] ?? null, 'after' => $value];
            }
        }

        return $diff;
    }

    public function readable(mixed $value): mixed
    {
        return $value;
    }
}
```

```php
// config/services.php
$services->set(ThingRevertTarget::class);
$services->alias(RevertTargetInterface::class, ThingRevertTarget::class);
```

### Tell how payloads reference a subject: `SubjectReferencesInterface`

In the Sylius Assistant stack, it is `Emagma\McpSyliusBundle\AdminApi\SyliusSubjectReferences`.

```php
use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;

final class ThingSubjectReferences implements SubjectReferencesInterface
{
    public function suffixes(string $type, string $id): array
    {
        return 'customer' === $type ? ['/customers/' . $id] : [];
    }
}
```

### Record from a tool

Inject `Emagma\McpCoreBundle\Journal\WriteJournalInterface`, never `AuditWriter`:

```php
$this->journal->record('rename_thing', 'thing', $code, $before, $after, $diff, $context);
```

## Configuration reference

Keys under `emagma_mcp_audit`, defined in `configure()` and exposed as parameters:

- **`table`**: default `mcp_audit`, parameter `emagma_mcp_audit.table`; the argument of `AuditTableListener`, which sets the entity's table on `loadClassMetadata`.
- **`retention`**: default `P1Y`, parameter `emagma_mcp_audit.retention`; the `$retention` of `PurgeAuditCommand`, validated as a `\DateInterval` when the container is built.

What `prependExtension()` prepends:

- `emagma_mcp_core.permission_domains`: `Emagma\McpAuditBundle\` => `core`, hence `mcp:core:audit:*` and `mcp:core:audit_all:*`.
- `mcp.servers.<server>.registry.tools`: `Emagma\McpAuditBundle\Tool\`.
- `monolog.channels`: `emagma_mcp_audit`, when MonologBundle is there.
- `doctrine.orm.mappings.EmagmaMcpAuditBundle`: the attribute mapping of `src/Entity`, prefix `Emagma\McpAuditBundle\Entity`, when DoctrineBundle is there.
- `doctrine_migrations.migrations_paths`: `Emagma\McpAuditBundle\Migrations` => `migrations/`, when DoctrineMigrationsBundle is there.

## Before you expose the server

The audit is the record of who changed what through the server: the backend's own history credits every change to the service account.
It is also a store of personal data, kept in the application's own database, not in the backend's.
Three decisions to review; the overview is in the [auth bundle's developer guide](../../mcp-auth-bundle/docs/developer-guide.md#before-you-expose-the-server).

### The actor of an entry

- **What it does**: each entry records the actor that the core's `ActorProviderInterface` returns.
- **Default**: `SecurityActorProvider` takes the Symfony user identifier, cut to 255 characters.
- **With the Keycloak bundle**: that identifier is the OIDC `sub` (`KeycloakUser::getUserIdentifier()`), opaque, stable, never reassigned, present in every token.
- **Result**: the audit holds no email address of the staff, and an entry still names the same person after a change of address in the identity provider.
- **Who is who**: the identity provider says who a `sub` is when someone needs to know.
- **Command line**: a write from the command line has no actor.
- **Risk of changing it**: the actor decides whose entries a caller reaches (`AuditScope`).
- **Risk, in detail**: a provider that returns another value (an email, say) makes the caller's older entries, recorded under the `sub`, unreachable without an `audit_all` grant, and writes that value into every new row.
- **Display**: the tools do not return the actor, so changing it does not make `search_mcp_edits` show a readable name.
- **What to set**: keep the default; to change it, alias `ActorProviderInterface` to your service, on an empty trail or with a migration of the `actor` column.

### How long entries are kept

- **What it does**: nothing is deleted on its own; `mcp:audit:purge` deletes the entries older than `emagma_mcp_audit.retention` (an ISO 8601 duration), or than `--older-than` for one run.
- **Dry run**: `--dry-run` counts and deletes nothing (see [Lifetime](#lifetime)).
- **Default**: `P1Y`, applied only when the command runs.
- **Risk**: without a scheduled run, entries, and the personal data left in them, are kept forever.
- **What to set**: the retention your data policy allows, and a scheduled job running the command (daily, for example).

```bash
bin/console mcp:audit:purge --dry-run            # what would go
bin/console mcp:audit:purge                      # apply emagma_mcp_audit.retention
bin/console mcp:audit:purge --older-than=P90D    # another duration, once
```

A revert younger than the cutoff whose original entry is purged keeps its row, with an empty `reverts`: what it undid is no longer in the trail.

### Erasure requests

- **How**: the tool `forget_audit_personal_data(type, id)`, or the command `mcp:audit:forget-personal-data <type> <id>` (`--dry-run` lists without changing).
- **Tool rules**: grant `mcp:core:audit_all:delete`, and a human confirmation whose preview lists the entries by id, tool and date, never a value.
- **What it covers**: for `customer <id>`, every entry written on that customer, and every entry whose snapshots reference it at any depth.
- **References**: a backend bundle says how its payloads reference a subject with `SubjectReferencesInterface` (see Extension points); with the Sylius bundle, a value ending in `/customers/<id>` (its orders, addresses, reviews).
- **What it does**: every value of those entries becomes the redaction marker.
- **Rows stay**: the trail still says which tool touched what, when and by whom, which is the trace the erasure itself must leave.
- **Locked**: a forgotten entry is never reverted again (`redacted_snapshot`) and never updated.
- **Trace**: a new entry `forget_audit_personal_data` on the same target records the erasure, with counts only.
- **Skipped**: an entry already forgotten is not counted.
- **Purge**: it deletes forgotten entries like the others.
- **What it does not cover**: the backend's own copy of the data; erasing it there is the backend's business.
- **Target codes**: a customer or an administrator is recorded by id, never by email, so an erased entry does not keep the address in `target_code`; the writer replaces an email given as target code by the marker.

## Data

One entity, `AuditEntry`, mapped by `Doctrine\ORM\Mapping` attributes on the class, table `mcp_audit` by default.
Its rows are written by the writer and the eraser only; a forgotten row is never updated again.

| Column | Type | Note |
|---|---|---|
| `id` | bigint | Auto-generated |
| `created_at` | datetime_immutable | Set in the constructor |
| `tool` | string(64) | |
| `target_type` | string(32) | |
| `target_code` | string(255) | An id, never an email |
| `before_state`, `after_state`, `diff` | json | Redacted |
| `session_id` | string(64), nullable | |
| `batch_id` | string(36), nullable | Shared by one batch call |
| `actor` | string(255), nullable | Null for the command line |
| `reverts_id` | self, nullable | `ON DELETE SET NULL` |
| `reverted_by_id` | self (one-to-one), nullable | `ON DELETE SET NULL` |
| `revertible` | boolean, default true | False for an irreversible tool |
| `forgotten_at` | datetime_immutable, nullable | Set by the erasure |
| `forgotten_by_id` | self, nullable | The trace entry; `ON DELETE SET NULL` |

Indexes: `idx_target` (`target_type`, `target_code`, `created_at`), `idx_session` (`session_id`, `created_at`), `idx_batch` (`batch_id`), `IDX_emagma_mcp_audit_actor` (`actor`), and the ones Doctrine derives for the self references.
They are declared in the attributes of the entity as well as in the migration.

### The entity and its repository

The entity is mapped by attributes, with its constructor-promoted fields: `readonly` when nothing changes them after construction.
The class is not `final`: Doctrine builds its proxies by inheritance.

`AuditRepository` is not a `ServiceEntityRepository`, and the mapping names no repository class.
It is a service of its own, built on the manager registry.

`$entityManager->getRepository(AuditEntry::class)` returns Doctrine's default repository.
Use it for the generic finders (`find`, `findBy`), and `AuditRepositoryInterface` for the audit's queries.

Inject the interfaces, not the service ids:

- `Emagma\McpCoreBundle\Journal\WriteJournalInterface` to record a write;
- `Emagma\McpAuditBundle\Repository\AuditRepositoryInterface` to query the audit.

The configuration values are the parameters `emagma_mcp_audit.table` and `emagma_mcp_audit.retention`.

### The bundle's migration

`migrations/Version20261122000000.php`, namespace `Emagma\McpAuditBundle\Migrations`, creates `mcp_audit` with the whole schema.

- **Platforms**: MySQL, MariaDB and SQLite.
- **Registration**: the bundle prepends its path to `doctrine_migrations.migrations_paths` when DoctrineMigrationsBundle is there.
- **Table already there**: the migration runs without SQL and is recorded as executed. It is not a `skipIf()`: Doctrine Migrations would leave a skipped migration pending.
- **Another platform, or another `table` name**: the host writes its own migration.
- **Order**: migrations run in the alphabetical order of their class names, so an application's `DoctrineMigrations\` run before the bundle's.
- **Rollback**: for the same reason, `doctrine:migrations:migrate prev` reaches the bundle's migration first; its `down()` keeps the table and its entries, dropping the audit is done by hand.
- **`doctrine:migrations:diff`**: with two namespaces it asks which one; pass `--namespace=DoctrineMigrations`.
- **A schema change**: a new migration in `migrations/` (MySQL, MariaDB and SQLite), never an edit of the existing one; the entity's attributes and `tests/Integration/MigrationTest.php` follow.

### In the Sylius Assistant stack

The stack keeps its own history in `apps/mcp-server/migrations`, and its table already exists.
The bundle's migration is a no-op there, recorded as executed.

## Tests and quality

The bundle's tests run on their own.
`phpunit.xml.dist` has two suites:

- **`mcp-audit-unit`** (`tests/Unit`): no database.
- **`mcp-audit-integration`** (`tests/Integration`): a minimal kernel, `tests/Integration/Fixture/TestKernel.php`, on in-memory SQLite, with the core, `symfony/mcp-bundle`, Doctrine and its migrations.

Unit tests:

- `tests/Unit/AuditWriterTest.php`: actor, redaction before persistence, `revertible`, an email as target code, a failed persist or flush reported and not thrown.
- `tests/Unit/AuditScopeTest.php`: own entries, `audit_all`, no identity, the recording tool's grant.
- `tests/Unit/Entity/AuditEntryTest.php`: the erasure, the lock, the trace.
- `tests/Unit/Tool/RevertMcpEditTest.php`: every refusal reason, the scope, the confirmation paths, the incomplete revert.
- `tests/Unit/Tool/ListMcpEditsTest.php`: `search_mcp_edits`, lean shape, paging limits, invalid `since`.
- `tests/Unit/Tool/GetMcpEditsTest.php`: found and missing ids in one query.
- `tests/Unit/Output/McpEditOutputTest.php`: both shapes and `DiffSummarizer`.
- `tests/Unit/EmagmaMcpAuditBundleTest.php`: configuration, defaults without a backend, mapping, migrations path, log channel.
- `tests/Unit/Conformance/*`: the core's conformance cases on the four tools.

Integration tests:

- `tests/Integration/AuditErasureTest.php`: the erasure against a real database.
- `tests/Integration/AuditLifetimeTest.php`: the purge and its references.
- `tests/Integration/AuditScopeTest.php`: the scopes against real entries.
- `tests/Integration/MigrationTest.php`: the migration creates the mapped schema, and is a no-op when the table exists.

Helper, not shipped: `tests/AuditTestTrait.php`.
No PHPUnit code lives in `src/`.
Tests mock `AuditRepositoryInterface`; the trait gives two helpers:

- `createAuditRepository(?AuditEntry $findResult = null, array $paginateItems = [], int $paginateTotal = 0)`: a stub of the interface.
- `createAuditScope(?array $grants = null, ?string $actor = null, array $tools = [], array $domains = [])`: a real `AuditScope` on the grant strings given (`null`: an explicit allow-all) and an actor.

Alone, from the package directory:

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

In the monorepo of the Sylius Assistant stack:

```bash
# the package alone, in a throwaway copy with its own vendor/
bin/ci/package.sh mcp-audit-bundle lowest
bin/ci/package.sh mcp-audit-bundle highest

# the server's tools on every bundle, from the host
castor mcp:quality
```

- `bin/ci/packages.sh` runs the package script for each package.
- `bin/ci/mcp-server.sh` and `castor mcp:quality` still check the bundle with the server's tools.
- The application keeps the tests that need the shop: `apps/mcp-server/tests/Integration/RevertThroughAdminApiTest.php` (it needs Sylius) and `RedactionWiringTest.php`.

## Traps

- **`AuditWriter` must never throw.** It runs after the backend has changed. A failed flush closes the EntityManager: the pending entries are read out of the unit of work first, then logged.
- **Indexes are declared in the entity's attributes as well as in the migration**: an index the mapping ignores makes `doctrine:schema:validate` diverge.
- **Changing `emagma_mcp_audit.table` renames nothing**: the host writes the migration.
- **A new column means a new migration in `migrations/`**, never an edit of the existing one, with the entity's attributes and `MigrationTest` kept in step.
- **The redaction marker comes from the core** (`emagma_mcp_core.redaction.marker`). `plan()` refuses a snapshot containing it; entries scrubbed under another marker would be replayed as data.
- **`PersonalDataEraser` and `RevertMcpEdit` have constructor defaults** (`new SensitiveDataRedactor()`, `new NoSubjectReferences()`) for unit tests; in the container they are wired with the configured services.
- **`AuditScope` has no default**: a permissive default would be a hole if the wiring changed.
- **The `audit_all` grants widen, they do not open**: `#[Permission]` is AND-only, so the tools require `audit:*` and the identity provider gives `audit:*` to whoever holds `audit_all:*`.
- **`revertible` is computed at write time** from the tool's class: marking a tool `#[Irreversible]` later leaves its older entries revertible (`revert_incomplete` catches them after the write).
- **`findConcerning()` scans every entry in PHP** (no JSON search that works on every platform): its cost grows with the table.
- **`search_mcp_edits` filters `since` with `>=`** in `AuditRepository::paginate()`, while the tool's description says "strictly after". Align one with the other before relying on the edge.
- **`tool` is 64 characters, `target_type` 32**: a longer name fails the insert, which the writer turns into a lost entry in the log.
- **Another `table` name needs your own migration**: the bundle's creates `mcp_audit` only.
- **Flex rewrites `config/bundles.php`** on `composer update`; the bundle must stay registered after the core.
