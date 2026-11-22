# MCP Knowledge Bundle: user guide

## What it does

The bundle lets staff ask the assistant what the internal procedures of the business say.

Returns, lost parcels, refunds, payment disputes: each procedure is a Markdown file the business writes.
The server indexes the files and answers a question with the passages that answer it, each with its source.

This guide is for the staff who ask in the chat, and for whoever writes and deploys the procedures.

## What happens in a conversation

1. A member of staff asks: "What do we do when a returned product arrives damaged?"
2. The model calls `search_knowledge` with the question.
3. The server answers with the closest passages: the procedure, the step, the text, the file.
4. The model answers from those passages only, names the procedure and its date, and says so when no procedure covers the case.

The procedures inform, they do not authorise.
To apply one to a real order, the model reads that order with the matching tool.

## The MCP tool `search_knowledge`

- **Arguments**: `question` (required), `limit` (1 to 10, 4 by default).
- **Returns**: `{items, total, mode}`, the closest passage first.
- **An item**: `title` (the procedure), `section` (the step), `text`, `file` (relative to the procedures directory), `team` and `updated` (from the front matter, null when absent).
- **`mode`**: `keywords` or `hybrid`, how the search was made.
- **Nothing found**: `items` is empty and a `hint` asks the model to try other words, then to say that no procedure covers the case.

## Writing a procedure

A procedure is one Markdown file: an optional front matter, a `#` title, an introduction, then one `##` section per step or case.

```markdown
---
title: Damaged product returned
team: customer-service
updated: 2026-11-10
---
# Damaged product returned

Applies when a customer sends back an item that arrives damaged at the warehouse.

## Check the item on receipt

Photograph the item and its parcel before anything else.
Note the order number on form R-12.

## Refund or exchange

If the damage happened in transit, refund the order line in full.
Offer an exchange when the same size is in stock.
```

- **Front matter**: `key: value` lines between two `---` at the very top.
  Three keys are used: `title`, `team`, `updated`.
  All are optional.
- **Title**: the front matter's `title`, else the first `#` heading, else the file name.
- **Introduction**: the text before the first `##`, indexed as the section `Introduction`.
- **Sections**: each `##` heading opens a passage.
  A passage is what a search finds and quotes: write one step or one case per section, with the words staff would use.
- **Empty sections** are not indexed.
- **Folders** are free (`returns/`, `shipping/`).
  They show in the `file` of each passage.

An index that follows the files: there is nothing to run after an edit.
Each search first indexes the files written or changed since the previous one, and forgets the deleted ones.

## Setting it up

In `config/packages/emagma_mcp_knowledge.yaml` of the application:

```yaml
emagma_mcp_knowledge:
    directory: '%kernel.project_dir%/knowledge'
    index_file: '%kernel.project_dir%/var/knowledge/index.sqlite'
```

- **`directory`**: where the procedures live, every `*.md` file, sub-directories included.
  By default the environment variable `MCP_KNOWLEDGE_DIR`, which falls back to `knowledge/` of the project.
- **`index_file`**: the SQLite file of the index.
  The server and its worker both write to it.
  It is rebuilt from the procedures at any time.

### Search by words or by meaning

By default the search is by words (`mode: keywords`): a passage matches when it contains words of the question.
"broken" does not find "damaged".
The tool asks the model to put synonyms in the question, and writing procedures with the usual words of the staff helps as much.

The search becomes hybrid, by words and by meaning (`mode: hybrid`), when you name a Symfony AI platform that serves embeddings and its model:

```yaml
emagma_mcp_knowledge:
    embeddings:
        platform: ai.platform.mistral
        model: mistral-embed
```

Both keys are needed: with one alone the search stays by words.
After changing them, rebuild the index: `bin/console mcp:knowledge:index --rebuild`.

## Who may use it

| MCP tool | Grant |
|---|---|
| `search_knowledge` | `mcp:knowledge:document:read` |

## Command

`mcp:knowledge:index` indexes the procedures written or changed since the last run and forgets the deleted ones.
It is the work a search does on its own.

- **`--rebuild`**: empties the index and reads every procedure again.
- **Output**: files indexed, removed and unchanged, the number of passages, the search mode.

```bash
bin/console mcp:knowledge:index --rebuild
```

## Limits and things to know

- **Words, not meaning, by default.**
  In `keywords` mode a question in French does not find a procedure written in English.
- **Short and common words are ignored.**
  Words under three letters and a list of English and French stop words are dropped, and only the first twelve remaining words are searched.
  A question made only of such words returns nothing.
- **The front matter is not YAML.**
  Only single `key: value` lines are read.
- **Only `.md` files** are read.
- **Nothing found although the file exists.**
  Check the file is under `directory` as the server sees it (inside a container), run `mcp:knowledge:index` and read the passage count, then `--rebuild`.
- **A missing directory is not an error.**
  The index is empty and every search returns nothing.
