# MCP Server: developer guide

## At a glance

- **Composer root**: `apps/mcp-server/composer.json`, Symfony 7.4, PHP 8.4.
- **Namespace**: `Emagma\SyliusMcpServer`.
- **MCP server**: `sylius-mcp-server` (`config/packages/mcp.yaml`), on HTTP at `/_mcp` and on stdio.
- **Port**: 8081, service `mcp-server` of `compose.yaml`, and its three Messenger consumers.
- **What it is**: an assembly of the `packages/mcp-*-bundle`, installed as Composer path repositories.
  The features, their tools and their tests live in the bundles.

## What the application owns

| Path | What |
|---|---|
| `src/Kernel.php` | An empty `MicroKernelTrait` kernel |
| `config/bundles.php` | The bundles assembled |
| `config/packages/` | One file per bundle that needs a value of this installation |
| `config/packages/security.yaml` | The firewall of `/_mcp` |
| `config/packages/mcp.yaml` | The server's identity and transports |
| `migrations/` | The history of the server's own database |
| `src/ServerSources.php` | Every tool and output DTO the server exposes, for the tests |
| `tests/` | The tests of the whole |
| `bin/tools-weight.php` | What `tools/list` weighs, per tool, without booting the kernel |

Everything else is a bundle's.
A bundle brings its own defaults (`prependExtension()`): its HTTP clients, its tool namespace on the server, its log channel, its Doctrine mapping.
What stays here is this installation's: the firewall, the server's identity and transports, trusted clients, URLs.

### The firewall

```yaml
# config/packages/security.yaml
mcp:
    pattern: ^/_mcp
    stateless: true
    entry_point: Emagma\McpAuthBundle\McpAuthenticationEntryPoint
    access_token:
        token_handler: Emagma\McpAuthKeycloakBundle\KeycloakAccessTokenHandler
```

The auth bundle answers the 401 and serves the RFC 9728 metadata; the Keycloak bundle checks the token and turns its realm roles into grants.
Another identity provider is another token handler here.

### The database

- **Who writes in it**: the audit, the tasks, the agents' reports, the shop's events, and the Messenger queues by default.
- **Migrations**: `migrations/`, namespace `DoctrineMigrations`.
  A bundle may ship its own (the audit does: a no-op here, recorded as executed), so a diff names the namespace:
  `castor mcp:console doctrine:migrations:diff --namespace=DoctrineMigrations`.
- **Apply**: `castor mcp:db` creates the database if missing and migrates it, the three consumers stopped.

## Adding a bundle

A new `packages/mcp-*-bundle` is wired in four places:

1. **`composer.json`**: a path repository with `only` its package and `options.versions` at `0.1.0`, and the requirement `^0.1`.
   Without the version, the lock records the branch name.
2. **`config/bundles.php`**.
3. **`PACKAGES` in `.castor/mcp.php`**, for `castor mcp:quality`.
4. **`deptrac.yaml`**: its paths (`src`, `tests`, `migrations` when it ships some) and its layer.

The CI scripts find it on their own.
When its tools must be covered by the conformance tests, add them to `ServerSources`.

## Tests

| Suite | What it proves |
|---|---|
| `tests/Conformance/` | The conventions of the tools, replayed on every tool of every bundle (`ServerSources`) |
| `tests/Integration/` | A revert through the Admin API |
| `tests/Functional/` | `/_mcp` on a booted kernel: the endpoint, the redaction wiring, agents woken by events |
| `tests/Qa/` | Business scenarios against a real shop loaded with the fixtures |

- **Conformance**: the abstract test cases live in the bundles (`Testing/Conformance` of the core and of the Sylius bundle).
  `ServerSourcesTest` fails when a scan shrinks below `MIN_TOOL_CLASSES` or `MIN_OUTPUT_CLASSES`: an empty scan proves nothing.
- **Functional**: needs `memory_limit=-1` (it compiles the discovery of every tool).
  Its Messenger transports are in memory.
- **QA**: its own configuration, `phpunit.qa.xml.dist`.
  `castor mcp:qa` starts a throwaway shop (`shop_qa` database, `shop-qa` container) and runs them; `--filter`, `--keep`, `--shop-url` for another 2.x shop already loaded.

```bash
castor mcp:quality             # ECS, PHPStan, deptrac, PHPUnit, of the server and of its bundles
castor mcp:quality:deptrac     # the boundaries between the bundles, code and tests, no baseline
castor mcp:qa                  # the QA scenarios
```

The CI runs the same: `bin/ci/mcp-server.sh`.

## Traps

- **`APP_ENV` is forced twice** in `phpunit.xml.dist`, through `<server>` and `<env>`.
  Dotenv and `KernelTestCase` read `$_SERVER` and `$_ENV` in opposite orders, and the container exports `APP_ENV=dev`.
- **`nyholm/psr7` is required for discovery.**
  The MCP SDK finds its PSR-17 factories by discovery: without an implementation, every request to `/_mcp` answers 500.
- **The redaction marker is this installation's** (`emagma_mcp_core.redaction.marker` in `config/packages/emagma_mcp_core.yaml`).
  The audit already holds it: changing it makes older scrubbed entries unrecognised.
- **`max_execution_time=60`** bounds a write confirmation: a client must answer before.
- **Emails go through the `async` transport**: nothing is sent while `mcp-agents-worker` is down.
- **`MCP_SHOP_EXTENSIONS` and `MCP_SYLIUS_VERSION` are read at runtime**: no cache clear, but the container must be recreated to see a new value.
