# MCP Sylius 2.x bundle: developer guide

- **Composer name**: `emagma/mcp-sylius-v2-bundle`
- **Namespace**: `Emagma\McpSyliusV2Bundle`
- **Config alias**: `emagma_mcp_sylius_v2` (no configuration tree)
- **Depends on**: `emagma/mcp-sylius-bundle` only
- **Depended on by**: the application only; no other bundle names its classes

## How it is built

Three classes and one service file:

| Piece | Role |
|---|---|
| `EmagmaMcpSyliusV2Bundle` | The bundle (`AbstractBundle`): loads `config/services.php` |
| `config/services.php` | Registers `emagma_mcp_sylius_v2.line`, tagged `emagma_mcp_sylius.line`, aliased by its class |
| `Sylius2Line` | `SyliusLineInterface`: serves `['2.0', '2.1', '2.2', '2.3']` |
| `Sylius2Adapter` | Extends `AbstractSyliusApiAdapter`, with no table of its own |

The default layout of the Sylius bundle is the layout of 2.x.
So `Sylius2Adapter` is empty:

```php
final readonly class Sylius2Adapter extends AbstractSyliusApiAdapter
{
}
```

A 2.x route that leaves the default layout is named there, in `ITEMS`, `COLLECTIONS` or `ACTIONS`.

## The flow

- `SyliusLines` (Sylius bundle) receives every service tagged `emagma_mcp_sylius.line`.
- It finds the line that lists the minor of `MCP_SYLIUS_VERSION`.
- It asks that line for its adapter, given the API URL.

## Extension points

None of its own.
It implements `SyliusLineInterface` of the Sylius bundle: see that bundle's developer guide.

## Configuration

No key.
The bundle prepends nothing.

## Data

None.

## Tests and quality

- `tests/Unit/Sylius2LineTest`:
  - the line serves 2.0 to 2.3 whatever the patch;
  - every `ApiFeature` of the 2.x line appears on a version the line serves;
  - the routes are the default layout.
- `tests/Integration/BundleOnItsOwnTest`, on a compiled kernel with the core and the Sylius bundle:
  - the line is registered and tagged;
  - `SyliusLines` serves `2.0` to `2.3` and refuses `2.4`;
  - the configured version reaches the Admin API client.

Alone:

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

From the repository: `bin/ci/package.sh mcp-sylius-v2-bundle highest` (or `lowest`).

## Traps

- Add a new 2.x minor to `Sylius2Line::VERSIONS` only once its routes are checked.
  A new major is a new bundle, not a new entry.
- An endpoint that appears in a later minor is not expressed here but as an `ApiFeature` case in the Sylius bundle.
- Two lines that list the same minor make `SyliusLines` throw a `LogicException` when it is built.
