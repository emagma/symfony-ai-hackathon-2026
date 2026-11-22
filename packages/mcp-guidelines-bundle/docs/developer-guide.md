# MCP Guidelines Bundle: developer guide

## Identity

- **Composer name**: `emagma/mcp-guidelines-bundle`
- **Namespace**: `Emagma\McpGuidelinesBundle`
- **Bundle**: `EmagmaMcpGuidelinesBundle`
- **Config alias**: `emagma_mcp_guidelines`
- **Depends on**: `emagma/mcp-core-bundle` (the server's instructions, the permissions) and `mcp/sdk`
- **Depended on by**: the application only, no bundle of tools knows it

> **Security.**
> The guidelines are put into the model's instructions as rules.
> The Markdown loader reads files reviewed and versioned with the application.
> A loader over a source people can edit outside that review (a CMS, a database) lets them, or a model through a write tool, write instructions: prompt injection without review.

## How it is built

| Class | Role |
|---|---|
| `EmagmaMcpGuidelinesBundle` | Configuration, prepended defaults, the compiler pass |
| `GuidelineLoaderInterface` | `load(string $resource): ?string`, the extension point |
| `Guideline` | A name and its Markdown content |
| `Loader\MarkdownGuidelineLoader` | Reads a `.md` file, alias `markdown` |
| `DependencyInjection\Compiler\GuidelinesPass` | Resolves and checks the resources at build |
| `GuidelineResolver` | Loads the guidelines once per request, answers by intent |
| `Instruction\GuidelinesInstruction` | The intents in the server's instructions, priority 0 |
| `Tool\GetGuidelines` | The MCP tool `get_guidelines(intents)` |
| `Command\ListGuidelinesCommand` | `mcp:guidelines` |

Services are declared in `config/services.php`, with ids `emagma_mcp_guidelines.*`.
`GuidelineResolver` is aliased to its class, which the tool autowires.
The `Tool/` namespace is loaded by namespace, autowired and autoconfigured.

## From the configuration to the model

1. The bundle gives the configured `resources` and `intents` to the resolver, and the `intents` to the instruction.
2. `GuidelinesPass`, when the container is built:
   - resolves each resource: `%parameters%`, then `@Bundle/...` through `kernel.bundles_metadata`,
   - gives it a loader: its `loader`, or `markdown` for a `.md` file,
   - names it: its `name`, or the file name without `.md`,
   - throws on an unknown loader, a missing Markdown file, a duplicate name or an intent naming an unknown guideline,
   - passes a service locator of the loaders and the resolved resources to the resolver.
3. `GuidelinesInstruction` writes the intents into the server's instructions, after the backend's conventions (priority 100).
   It writes nothing without an intent.
4. `GuidelineResolver::forIntents()` returns the guidelines of the intents, each once, in order, and the unknown intents.
5. `GuidelineResolver::all()` loads each resource through its loader once per request (`kernel.reset`).
   What cannot be read is left out and logged.

## Extension points

### Another loader

Implement `GuidelineLoaderInterface` and tag the service `emagma_mcp_guidelines.loader` with an `alias`.

```php
use Emagma\McpGuidelinesBundle\GuidelineLoaderInterface;

final readonly class WikiGuidelineLoader implements GuidelineLoaderInterface
{
    public function load(string $resource): ?string
    {
        return null; // the Markdown text; null when it cannot be read now, never throw
    }
}
```

```php
// config/services.php
$services->set(WikiGuidelineLoader::class)
    ->tag('emagma_mcp_guidelines.loader', ['alias' => 'wiki']);
```

```yaml
emagma_mcp_guidelines:
    resources:
        - { resource: 'https://wiki.example.com/legal', loader: wiki, name: legal_notice }
```

Read the security notice before writing one over a dynamic source.

### The instruction

`GuidelinesInstruction` is an instruction of the core.
Change its priority with its tag, or its text with a decorator.

## Configuration reference

| Key | Default | Notes |
|---|---|---|
| `resources` | the six of `resources/guidelines` | A list of paths, or `{resource, loader, name}` |
| `intents` | none | `name: {description, guidelines: [...]}` |

The bundle prepends to the core:

- the permission domain `core` for `Emagma\McpGuidelinesBundle\`,
- the namespace `Emagma\McpGuidelinesBundle\Tool\` in the server's registry.

`GetGuidelines` declares `#[Permission(resource: 'guideline', action: Action::Read)]`, the grant `mcp:core:guideline:read`.

`EmagmaMcpGuidelinesBundle::getPath()` is the package root, so `@EmagmaMcpGuidelinesBundle/resources/...` resolves to this package.

## Data

No entity, no table, nothing written.
The bundle only reads its resources.

## Tests and quality

The tests run on their own, on the lowest and the highest dependencies:

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

- `tests/Unit`: the pass and the configuration, the resolver, the instruction, the tool, the Markdown loader, and the conformance cases of the core.
- `tests/Integration`: a compiled kernel with the core and this bundle, the instructions and a call to `get_guidelines` through the core's chain.

## Traps

- **A new resource or intent needs `cache:clear`.**
  They are resolved at build, an edited file is not.
- **`%env()%` is not supported in a resource.**
  It is resolved at build.
- **The model chooses the intent.**
  A vague description makes it miss.
