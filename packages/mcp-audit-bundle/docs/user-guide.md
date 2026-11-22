# MCP Audit Bundle: user guide

The audit bundle records what was changed through an MCP server: which MCP Tool wrote what, on which resource, for whom, with the state before and after.

It lets a user of the chat look the audit up and undo their own edits.
It lets an auditor read every entry and erase a person's data on request.
It gives the operator two commands to apply the audit's lifetime and an erasure request.

- **Audit entry**: one row per write call, or per person revealed (`reveal: true`).
- **Forgotten entry**: an entry whose values were erased on request, locked for good.

## What you can do

| MCP Tool | Purpose |
|---|---|
| `search_mcp_edits` | List past edits, filtered |
| `get_mcp_edits` | Read entries in full, by id |
| `revert_mcp_edit` | Put a resource back as it was before an edit |
| `forget_audit_personal_data` | Erase what the audit recorded about a person |

Each tool serves the caller's own entries (the `actor` is the caller), or every entry under the `audit_all` grants (see "Who may use it").
Another caller's entry answers as a missing one.

### `search_mcp_edits`

- **For**: finding an edit, usually the one to undo.
- **Ask in the chat**: "What did the assistant change on the taxon dresses since yesterday?"
- **Filters**: `targetType` (e.g. `taxon`), `targetCode` (e.g. `dresses`), `sessionId`, `batchId` (every item of one batch call), `since` (an ISO 8601 date and time), `includeReverted` (default `true`, `false` hides edits already reverted).
- **Paging**: `page` (default 1), `itemsPerPage` (default 30, at most 50).
- **Returns**: `{items, total, page, hasMore}`, the latest first.
- **Item**: lean, with `id`, `createdAt`, `tool`, `targetType`, `targetCode`, `summary` (e.g. `name: "Old" -> "New"`), `reverted`, `revertsId`.
- **Total**: `total` counts what the caller may see.
- **Target codes**: a person (a customer, an administrator) is targeted by id (`customer` `42`), never by email.

### `get_mcp_edits`

- **For**: seeing exactly what an edit changed, before reverting it.
- **Ask in the chat**: "Show me the detail of edit 42."
- **Argument**: `ids`, a list of entry ids, at least one, at most 50.
- **Returns**: `{found: [...], missing: [ids]}`.
- **Entry found**: the lean fields, plus `sessionId`, `batchId`, `revertedById`, `diff`, `before` and `after`.
- **Refusals**: more than 50 ids answers `reason: too_many_ids`; another caller's entry is in `missing`.

### `revert_mcp_edit`

- **For**: writing back the state captured before an edit.
- **Ask in the chat**: "Undo the last change you made to the product blue-jeans."
- **Arguments**: `id` (one edit) or `batchId` (every edit of one batch write, under one confirmation), one of the two and not both; `confirm`.
- **Reach**: the caller's own edits, or any edit under `mcp:core:audit_all:write`.
- **Second grant**: the caller also needs the grant of the MCP Tool that made the edit (reverting `upsert_products` needs `mcp:sylius:product:write` with the Sylius bundle).
- **Confirmation**: a person approves, with a preview of each field going from its current value to the restored one.
- **Confirmation per client**: see the core bundle's user guide.
- **Returns** on success: `{ok: true, reverted: true, id, newEntryId, targetType, targetCode, diff}`.
- **Trace**: the revert is itself recorded as a new entry.
- **With `batchId`**: `{ok, batchId, total, reverted, failed, results}`, one result per entry; an edit that cannot be reverted does not stop the others.

When it does not revert, `reason` says why:

- `id_or_batch_id`: give `id` or `batchId`, exactly one.
- `not_found`: no such entry or batch, or not the caller's.
- `forbidden`: the caller lacks the grant of the MCP Tool that made the edit; `tool` names it.
- `already_reverted_by`: already reverted; `revertedById` names the entry.
- `nothing_to_restore`: a creation, or an irreversible MCP Tool (deletion, state transition, email sent).
- `redacted_snapshot`: the stored state was scrubbed, or the entry was forgotten.
- `unrevertable_snapshot`: the backend has no address to restore this to.
- `target_not_found`: the resource no longer exists.
- `already_at_target_state`: nothing differs from the stored state.
- `revert_incomplete`: the backend kept some fields; `notApplied` lists them.
- `nothing_to_revert`: no edit of the batch can be reverted.

`not_found`, `forbidden`, `already_reverted_by`, `redacted_snapshot` and `nothing_to_restore` are answered before the backend is read.

### `forget_audit_personal_data`

- **For**: an erasure request (GDPR).
- **Effect**: every value the audit recorded about the person becomes the redaction marker; the rows and their keys stay, and the entries are locked.
- **Ask in the chat**: "Erase what the audit holds on customer 42."
- **Arguments**: `type`, the target type as the audit records it (`customer`); `id`, its id; `confirm`.
- **Confirmation**: a person approves, with the list of the entries (id, MCP Tool, date), never a value.
- **Reach**: the entries written on the subject, and the entries whose snapshots reference it, whoever wrote them.
- **References**: what counts as a reference comes from the backend bundle; with the Sylius bundle it is a value ending in `/customers/42`.
- **Returns**: `{ok: true, type, id, forgotten, ids}`.
- **Skipped**: entries already forgotten are not counted; `reason: not_found` when no entry concerns the subject.
- **Trace**: one new entry, `forget_audit_personal_data` on the same target, with counts only; it is locked too.

### Background behaviour

Every write tool of the server records its change here once the backend has changed.
Secrets and personal data are scrubbed before the entry is stored (see the core bundle's user guide), and an email given as target code is replaced by the marker.

Each entry also keeps:

- the actor, the identity of the caller (the Keycloak `sub` in the Sylius Assistant stack);
- the MCP session id;
- whether the MCP Tool that made it can be reverted at all.

## Setting it up

Install the bundle and create its table:

```bash
composer require emagma/mcp-audit-bundle
bin/console doctrine:migrations:migrate
```

The bundle needs `emagma/mcp-core-bundle`, Doctrine ORM with DoctrineBundle, and an MCP server declared with `symfony/mcp-bundle`.
Register it after the core in `config/bundles.php`.

Configuration keys, under `emagma_mcp_audit` (`config/packages/emagma_mcp_audit.yaml`):

| Key | Default | Purpose |
|---|---|---|
| `table` | `mcp_audit` | The table of the audit |
| `retention` | `P1Y` | How long an entry is kept, as an ISO 8601 duration |

```yaml
emagma_mcp_audit:
    retention: P90D
```

- `retention` deletes nothing by itself: `mcp:audit:purge` applies it when run.
- The audit lives in the application's Doctrine database.
- No environment variable belongs to this bundle.

### The table

The bundle ships a migration that creates `mcp_audit`, on MySQL, MariaDB and SQLite.
With DoctrineMigrationsBundle installed, its path is added to `doctrine_migrations.migrations_paths` and `doctrine:migrations:migrate` runs it.

- **Table already there**: the migration does nothing and is recorded as executed.
- **Another platform, or another `table` name**: write the migration yourself (`doctrine:schema:update --dump-sql` gives the SQL).
- **Order**: migrations run in the alphabetical order of their class names, so those of the application's `DoctrineMigrations\` run before the bundle's.
- **`doctrine:migrations:diff`**: with two namespaces it asks which one; pass `--namespace=DoctrineMigrations`.

### In the Sylius Assistant stack

- This stack sets neither key: there is no `emagma_mcp_audit.yaml` in `apps/mcp-server/config/packages`.
- The audit lives in the MCP server's own database (`DATABASE_URL` of `apps/mcp-server`), created by `castor mcp:db`.
- The stack keeps the history of its table in `apps/mcp-server/migrations`.

## Who may use it

| Grant | Gives |
|---|---|
| `mcp:core:audit:read` | `search_mcp_edits`, `get_mcp_edits` on the caller's own entries |
| `mcp:core:audit:write` | The same, and `revert_mcp_edit` on the caller's own entries |
| `mcp:core:audit_all:read` | Every entry in the two read tools |
| `mcp:core:audit_all:write` | `revert_mcp_edit` on any entry |
| `mcp:core:audit_all:delete` | `forget_audit_personal_data` |

- `write` and `delete` each give `read`.
- The `audit_all` grants widen the tools, they do not open them: `search_mcp_edits` needs `audit:read` (or `audit:write`), `revert_mcp_edit` needs `audit:write`, whatever `audit_all` adds.
- An entry without actor (written from the command line) is reached through `audit_all` only.
- A revert also needs the grant of the MCP Tool that made the edit.
- How a caller gets grants depends on your identity provider; see the auth bundles' guides.

### In the Sylius Assistant stack

The realm generated by `.castor/keycloak-realm.php` gives the grants through roles and groups:

- **`/MCP/Audit/Readers`**: their own entries, read.
- **`/MCP/Audit/Managers`**: every entry read, the erasure, no revert.
- **Every `Managers` group of a theme**: their own entries, read and reverted.
- **`sylius-mcp-reader`**: their own entries, read.
- **`sylius-admin`**: every grant.

Details:

- **Composite roles**: `audit-read` / `audit-write` hold the `audit` grant of their action, `audit-all-read` / `-write` / `-delete` the `audit_all` one.
- **`/MCP/Audit/Managers`**: holds `audit-read`, `audit-all-read` and `audit-all-delete`; each theme's `Managers` group holds `audit-write`.
- **Demo user**: `auditor` belongs to `/MCP/Audit/Managers`.

## Commands

Run with `bin/console <cmd>`.
In the Sylius Assistant stack, run them with `castor mcp:console <cmd>`.

### `mcp:audit:purge`

- **For**: deleting the entries older than the retention; meant for a cron of the integrator.
- **Options**: `--older-than=<duration>` overrides `emagma_mcp_audit.retention` (e.g. `P90D`); `--dry-run` counts what would be deleted and deletes nothing.
- **Example**: `bin/console mcp:audit:purge --older-than=P90D --dry-run`

### `mcp:audit:forget-personal-data <type> <id>`

- **For**: an erasure request from the command line: the same erasure as `forget_audit_personal_data`, the same trace entry, without actor.
- **Arguments**: `type`, the target type as the audit records it (`customer`); `id`, the target code.
- **Option**: `--dry-run` lists the entries that would be forgotten and changes nothing.
- **Example**: `bin/console mcp:audit:forget-personal-data customer 12`
- **Reach**: the entries written on the subject itself, and the entries whose snapshots reference it (with the Sylius bundle, a value ending in `/customers/12`).
- **Skipped**: entries already forgotten are listed and skipped.

## Limits and things to know

- **A revert undoes an update, nothing else.** Not a creation, not a deletion, not a state transition (an order cancelled, a payment refunded, a shipment shipped, a review accepted): writing the old state back does not move a state machine.
- **Irreversible MCP Tools** are marked, and their entries answer `nothing_to_restore` at once.
- **A missing mark**: an entry recorded before the mark, or by an MCP Tool that should carry it and does not, is caught after the write; `revert_incomplete` names the fields that did not move.
- **An entry of an MCP Tool that no longer exists** (renamed) answers `forbidden`: there is no grant left to check.
- **A revert restores the whole state captured before the edit**, so it also overwrites what was changed on the same resource since.
- **Edits on resources holding secrets or personal data are often not revertable**: their snapshot was scrubbed (`redacted_snapshot`).
- **A forgotten entry is locked**: never reverted, never changed again.
- **A forgotten entry is still read** (it is the proof of the erasure) and still deleted by the purge.
- **The erasure covers the audit's copy only**, not the backend's own data.
- **The actor is stored but not returned by the tools.** It decides whose entries a caller reaches; there is no argument to filter on another actor.
- **An invalid `since` is ignored**, not refused: the search then returns everything.
- **Purging**: a younger revert of a deleted entry keeps its row, with an empty `revertsId`.
- **A change is missing from the audit**: the write happened but the entry could not be stored.
- **Where to look then**: nothing fails for the user; the entry is in the application's log at critical level (channel `emagma_mcp_audit`, message "MCP audit entry could not be persisted").
- **Only writes made through the MCP server are recorded.** A change made directly in the backend is not.
