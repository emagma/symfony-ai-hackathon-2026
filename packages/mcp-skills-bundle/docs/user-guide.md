# MCP Skills Bundle: user guide

## What a skill is

A skill is a proven method of the business, written once as Markdown.

Examples: a morning brief, opening a sales channel, a stock check.

It tells a model how to do the task with the tools of the MCP server, step by step.

The business keeps its skills as files.
The staff find them in their chat, each one seeing only the skills their grants allow them to run.

The format is the one of [Agent Skills](https://agentskills.io): a client that knows skills can install them as they are.

This guide is for the merchant who writes skills and for whoever configures the server.

## What you can do

### `list_skills`

- **For**: knowing which skills exist for you, or whether an installed one has a newer version.
- **What to type**: "Which skills does the shop offer?"
- **Arguments**: none.
- **Returns**: `{items: [{name, description, version, uri}], total, pack}`.
- **Which skills**: only those whose every required grant the caller holds.
- **`uri`**: `skill://<pack>/<name>/SKILL.md`.

### `get_skill`

- **For**: installing a skill in a client that keeps skills, or following it right away.
- **What to type**: "Give me my morning brief."
- **Arguments**: `name`, as `list_skills` gives it.
- **Returns**: a line of JSON `{found, name, version, files}`, then each file of the skill as an embedded resource `skill://<pack>/<name>/<file>`.
- **Order**: `SKILL.md` first, whole, front matter included, then its annexes.
- **Unknown or not allowed**: `{found: false, reason: "not_found", name}`.
  A skill the caller may not run answers as an unknown one.

## Setting it up

| Setting | Default | What it is |
|---|---|---|
| `MCP_SKILLS_DIR` | none | The directory of the skills |
| `emagma_mcp_skills.directory` | `%env(resolve:MCP_SKILLS_DIR)%` | Same, as a configuration key |
| `emagma_mcp_skills.pack` | `merchant` | The name of the set, in the URIs |

```yaml
# config/packages/emagma_mcp_skills.yaml
emagma_mcp_skills:
    directory: '%kernel.project_dir%/skills'
    pack: acme
```

- **`directory`**: set the key, or define the variable `MCP_SKILLS_DIR`.
  Without either, the container does not build.
- **`pack`**: lowercase words joined by hyphens.
- **A missing or empty directory** gives an empty list, not an error.

## Writing a skill

One folder per skill, named like the skill, holding a `SKILL.md`:

```
skills/
  morning-brief/
    SKILL.md
  stock-check/
    SKILL.md
    thresholds.md
```

A `SKILL.md` starts with a YAML front matter between two `---` lines, then the instructions in Markdown.

```markdown
---
name: morning-brief
description: What to handle first today. Use it when the merchant asks for their morning brief, a daily summary, or what to deal with first.
metadata:
  version: "1.0.0"
  requires: "sylius:order:read sylius:payment:read"
---
# Morning brief

Tell the merchant what needs them this morning, most urgent first.
You read, you change nothing.

## 1. Gather

Call `search_orders` for the orders waiting for a payment.
```

| Field | Rule |
|---|---|
| `name` | Lowercase letters and digits, words joined by hyphens, equal to the folder's name |
| `description` | Required: what the skill does and when to use it |
| `metadata.version` | Optional, `"1"` when absent, quote it (`"1.0.0"`) |
| `metadata.requires` | Optional: the grants of the tools the skill uses |

- **`requires`** is one string of grants `<domain>:<resource>:<action>` separated by spaces or commas.
  No `mcp:` prefix: `sylius:order:read`.
  The skill is listed only for callers who hold them all.
  List the grants of every tool the instructions name.
- **`description`** is what tells a model when to use the skill.
  Write the requests that should trigger it.
- **Annexes**: any other file of the folder, sub-folders included, is served with the skill.
  A file over 200,000 bytes is left out.
- **The body**: name the tools and their arguments, say what to do when a read fails, and how to present the result.
- **No restart**: a skill added or edited on disk is served on the next request.

## Who may use it

| What | Grant |
|---|---|
| `list_skills`, `get_skill` | `mcp:core:skill:read` |
| Seeing and getting one skill | every grant of its `metadata.requires` |

- **Write implies read**: a caller holding `mcp:sylius:order:write` satisfies `sylius:order:read`.
- **The instructions do not change what the user may do**: every tool the skill calls is checked as usual.

## Limits and things to know

- **A skill is missing from the list.**
  Check, in this order:
  - its folder is directly under the skills directory,
  - it holds a `SKILL.md` with a front matter,
  - `name` equals the folder's name (no capital, no underscore, no space),
  - `description` is not empty,
  - every entry of `requires` is `<domain>:<resource>:<action>`,
  - the user holds all of them.
- **An invalid folder is left out.**
  It never stops the server.
- **The bundle does not run a skill.**
  The model of the client follows the instructions: how well depends on that model.
- **A client that installs a skill copies its files.**
  `list_skills` shows when the served version differs from the installed one.
