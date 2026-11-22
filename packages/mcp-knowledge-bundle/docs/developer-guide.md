# MCP Knowledge Bundle: developer guide

## At a glance

- **Composer name**: `emagma/mcp-knowledge-bundle`
- **Namespace**: `Emagma\McpKnowledgeBundle`, bundle class `EmagmaMcpKnowledgeBundle`
- **Config alias**: `emagma_mcp_knowledge`
- **Depends on**: `emagma/mcp-core-bundle` (permissions, server registration), `symfony/ai-store`, `symfony/ai-sqlite-store`, `symfony/ai-platform`, `symfony/finder`, `symfony/filesystem`, `ext-pdo_sqlite`.
- **Nothing else**: no entity, no Doctrine table, no migration.

## How it is built

| Class | Directory | Role |
|---|---|---|
| `ProcedureLoader` | `src/Procedure/` | Markdown files to one `TextDocument` per section |
| `KnowledgeIndex` | `src/Index/` | Keeps the store in step with the files, searches |
| `Keywords` | `src/Index/` | The words of a question worth searching |
| `Passage` | `src/Index/` | One search result |
| `SqliteConnectionFactory` | `src/Index/` | Creates the directory, opens the PDO |
| `SearchKnowledge` | `src/Tool/` | The MCP tool `search_knowledge` |
| `IndexKnowledgeCommand` | `src/Command/` | `mcp:knowledge:index` |

The services are explicit, in `config/services.php`, with ids prefixed by the alias (`emagma_mcp_knowledge.index`, `.store`, `.pdo`, `.procedure_loader`, `.command.index`).
The tool is loaded by namespace with autowiring, as every `Tool/` namespace is.
`KnowledgeIndex` is aliased to its class.

### A search

1. `SearchKnowledge` clamps `limit` to 1..10 and calls `KnowledgeIndex::search()`.
2. On its first search since the last kernel reset, the index calls `sync()`.
3. `Keywords::of()` lowercases the question, splits it on non letters and digits, drops words under three characters and the stop words, and keeps the first twelve.
   No keyword: no passage.
4. Without a vectorizer the query is a `TextQuery` of the keywords.
   With one, it is a `HybridQuery` of the question's vector and the keywords.
5. Each stored document becomes a `Passage`.
   The stored text opens with `"<title> - <section>"` and a blank line: the passage's `text` is what follows.
6. The tool returns `{items, total, mode}` and adds a `hint` when `items` is empty.

### A sync

`KnowledgeIndex::sync(bool $rebuild = false)` returns `{indexed, removed, unchanged}`, in files.

1. `$store->setup()`.
   With `$rebuild`, `$store->clear()` and an empty manifest.
2. The manifest, `<index_file>.manifest.json`, maps each relative file to its `sha1_file` hash and the ids of its documents.
3. A file of the manifest no longer on disk: its documents are removed from the store.
4. A file whose hash changed, or a new one: its previous documents are removed, the loader reads it again, the documents are added.
5. The manifest is written only when something changed, or on a rebuild.

### The loader

`ProcedureLoader` implements Symfony AI's `LoaderInterface`.
For each `*.md` file (Finder, recursive, sorted by name):

- the front matter is `key: value` lines between two `---`, keys lowercased, values trimmed of quotes;
- the title is the front matter's `title`, else the first `#` heading, else the file name;
- the body is split on `^##\s+(.+)$`, and what precedes the first `##` is the section `Introduction`;
- `#` heading lines are removed from the text, and an empty section yields no document;
- a document's id is `sha1("<file>#<section index>")`, its content `"<title> - <section>\n\n<text>"`;
- its metadata are `file`, `section`, `team`, `updated` (`ProcedureLoader::KEY_*`), the title, the source and the text.

## Extension points

The bundle declares no interface, attribute or tag of its own.

### `KnowledgeIndex`

Autowirable by its class, for an agent or a job that needs the procedures:

```php
use Emagma\McpKnowledgeBundle\Index\KnowledgeIndex;

final readonly class ReturnsAdvisor
{
    public function __construct(private KnowledgeIndex $knowledge)
    {
    }

    public function passagesFor(string $question): array
    {
        // list<Passage>: title, section, text, file, team, updated
        return $this->knowledge->search($question, 3);
    }
}
```

### Hybrid search

No code: name a platform and a model in `embeddings`.
The bundle builds a `Symfony\AI\Store\Document\Vectorizer` from them.

## Configuration reference

| Key | Default | What the bundle does |
|---|---|---|
| `directory` | `%env(resolve:MCP_KNOWLEDGE_DIR)%` | the directory of `KnowledgeIndex` |
| `index_file` | `%kernel.project_dir%/var/knowledge/index.sqlite` | the argument of the `pdo` service, and the manifest beside it |
| `embeddings.platform` | `null` | with `model`, a `Vectorizer` for the index |
| `embeddings.model` | `null` | see above |

- `directory` and `index_file` cannot be empty.
- The bundle sets the parameter `env(MCP_KNOWLEDGE_DIR)` to `%kernel.project_dir%/knowledge` when the host defines none.
- It prepends to the core the permission domain `knowledge` for its namespace, and its `Tool\` namespace in the server's registry.
- `SearchKnowledge` declares `#[Permission(resource: 'document', action: Action::Read)]`, hence the grant `mcp:knowledge:document:read`.

## Data

- **`var/knowledge/index.sqlite`**: the Symfony AI SQLite store, table `mcp_knowledge`.
  Its directory is created on first use.
- **`var/knowledge/index.sqlite.manifest.json`**: file, hash and document ids.
- Both are rebuilt from the procedures at any time: `mcp:knowledge:index --rebuild`.
- Without a vectorizer, every document is stored with the placeholder vector `[0.0]`: the store wants a vector, the text search never reads it.

## Tests and quality

The package runs on its own, on a minimal kernel:

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

- `tests/Unit/ProcedureLoaderTest.php`: sections and front matter, the title without front matter, sub-directories, a missing directory.
- `tests/Unit/KnowledgeIndexTest.php`: a question finds its passage and source, an uncovered question finds nothing, the index follows changed, added and deleted files, `Keywords`.
  It runs on a real SQLite store in a temporary directory, with the fixtures of `tests/Unit/fixtures/`.
- `tests/Unit/DependencyInjection/`: the configuration and the hybrid vectorizer.
- `tests/Unit/Conformance/`: the core's conformance test cases over the tool.
- `tests/Integration/`: a compiled kernel with the core, the search through the tool chain, the command.

## Traps

- **A change of embeddings needs `--rebuild`.**
  The manifest only tracks file hashes: documents indexed with the placeholder vector stay as they are until the index is emptied.
- **`embeddings` needs both keys.**
  With one alone no vectorizer is configured, and nothing says so.
- **Two writers.**
  The server and its worker open the same SQLite file and the same manifest.
- **`kernel.reset` re-arms the sync.**
  A long-lived process looks at the files again on its next search.
  Within one request the files are scanned once.
- **Document ids depend on the section's position.**
  Inserting a section shifts the ids of the following ones.
  Harmless: a changed file is removed and indexed again as a whole.
- **`limit` is clamped twice**: by the `#[Schema(minimum: 1, maximum: 10)]` of the argument and by `max(1, min(10, $limit))` in the body.
  Change both.
- **Stop words are a fixed English and French list** in `Keywords`.
- **A missing directory is silent.**
  `ProcedureLoader::files()` returns `[]` and the index is empty.
