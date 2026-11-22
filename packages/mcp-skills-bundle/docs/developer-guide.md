# MCP Skills Bundle: developer guide

## Identity

- **Composer name**: `emagma/mcp-skills-bundle`
- **Namespace**: `Emagma\McpSkillsBundle`
- **Bundle**: `EmagmaMcpSkillsBundle`
- **Config alias**: `emagma_mcp_skills`
- **Depends on**: `emagma/mcp-core-bundle` (the permissions, the caller's grants), `mcp/sdk`, `symfony/yaml`, `symfony/finder`
- **Depended on by**: the application only

## How it is built

| Class | Role |
|---|---|
| `EmagmaMcpSkillsBundle` | Configuration, prepended defaults |
| `SkillRepository` | Reads the directory into `Skill` objects |
| `Skill` | One skill: name, description, version, requires, files |
| `Tool\ListSkills` | The MCP tool `list_skills` |
| `Tool\GetSkill` | The MCP tool `get_skill` |

Services are declared in `config/services.php`, with ids `emagma_mcp_skills.*`.
`SkillRepository` is aliased to its class, which the tools autowire.
The `Tool/` namespace is loaded by namespace, autowired and autoconfigured, with `$pack` bound to the parameter `emagma_mcp_skills.pack`.

## SkillRepository

`all()` walks the folders directly under the directory (sorted by name) and reads each:

1. `SKILL.md` must exist and start with a front matter, parsed with `Yaml::parse()`.
2. `name`: a string of lowercase words joined by hyphens, equal to the folder's name.
3. `description`: a non-empty string.
4. `metadata.version`: a scalar cast to string, `'1'` otherwise.
5. `metadata.requires`: a string split on spaces and commas.
   Each grant `<domain>:<resource>:<action>` becomes `new Permission($resource, $action, $domain)`.
6. Files: `SKILL.md` first (its whole text), then every other file of the folder, recursively, sorted by relative path.
   A file over 200,000 bytes is skipped.

A folder that fails raises an `\InvalidArgumentException` caught by `all()`.
The folder is left out and its reason kept in `problems()` (folder => why).

The result is kept for the request.
The service is tagged `kernel.reset` with the method `forget()`: the files are read again on the next request.

## The two tools

Both carry `#[Permission(resource: 'skill', action: Action::Read)]` and filter on `GrantsProviderInterface::grants()->allowsAll($skill->requires)`.

- **`ListSkills`** returns `{items, total, pack}`.
  An item is `name`, `description`, `version`, `uri`.
- **`GetSkill`** returns a `list<Content>`:
  - a `TextContent` of JSON (`found`, `name`, `version`, `files`),
  - then one `EmbeddedResource` per file, with the URI `Skill::uri($pack, $file)`,
  - the MIME type is `text/markdown` for `.md`, `text/plain` otherwise.
- **Unknown or not allowed**: one `TextContent` `{found: false, reason: "not_found", name}`.
  Do not add a `forbidden` reason: the list is what tells a caller which skills exist for them.

## Extension points

A skill is a folder, not code: there is no interface, attribute or tag to implement.

Another bundle can read the repository:

```php
use Emagma\McpSkillsBundle\SkillRepository;

final readonly class SkillsReport
{
    public function __construct(private SkillRepository $skills)
    {
    }

    /**
     * @return array<string, string> folder => why it is not a skill
     */
    public function invalid(): array
    {
        return $this->skills->problems();
    }
}
```

## Configuration reference

| Key | Default | Rule |
|---|---|---|
| `directory` | `%env(resolve:MCP_SKILLS_DIR)%` | Not empty |
| `pack` | `merchant` | Lowercase words joined by hyphens |

The bundle sets the parameters `emagma_mcp_skills.directory` and `emagma_mcp_skills.pack`.

It prepends to the core:

- the permission domain `core` for `Emagma\McpSkillsBundle\`, hence the grant `mcp:core:skill:read`,
- the namespace `Emagma\McpSkillsBundle\Tool\` in the server's registry.

## Data

No entity, no table, nothing written.
The bundle only reads the skills directory.

## Tests and quality

The tests run on their own, on the lowest and the highest dependencies:

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

- `tests/Unit`: the repository, `Skill` and both tools against `tests/Unit/fixtures/`, and the conformance cases of the core.
  `stock-check` is a valid skill with an annex, `broken` has a name that is not valid.
- `tests/Integration`: a compiled kernel with the core and this bundle, a configured directory and pack, and calls to both tools through the core's chain.

## Traps

- **`problems()` is read by nothing in the bundle.**
  A skill that silently disappears from `list_skills` is the only symptom.
- **Hyphens, not underscores**: the name pattern refuses `morning_brief`.
- **`requires` omits the `mcp:` prefix** (`sylius:order:read`), unlike the grants of a token.
  A grant with the prefix has four parts and makes the folder invalid.
- **`requires` is a string**, not a YAML list: a list makes the folder invalid.
- **An unquoted version** such as `1.0` is a YAML float and is served as `"1"`: quote it.
- **`requires` is declared, not derived.**
  Nothing checks that it covers the tools the instructions name.
- **Files travel as text.**
  A binary file in a skill's folder is not handled.
- **The front matter must end with a line break after the closing `---`.**
