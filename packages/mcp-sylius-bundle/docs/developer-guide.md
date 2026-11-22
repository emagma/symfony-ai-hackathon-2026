# MCP Sylius bundle - developer guide

- **Composer name**: `emagma/mcp-sylius-bundle`
- **Namespace**: `Emagma\McpSyliusBundle`, bundle class `EmagmaMcpSyliusBundle`
- **Config alias**: `emagma_mcp_sylius`
- **Depends on**: `emagma/mcp-core-bundle`, `mcp/sdk`, `symfony/mcp-bundle`, `symfony/http-client`
- **Used by**: the Sylius line bundle (`emagma/mcp-sylius-v2-bundle`), `emagma/mcp-sylius-screens-bundle`, and any bundle that brings tools for a Sylius shop
- **Does not know**: the audit bundle (it records through the core's `WriteJournalInterface`), nor any line bundle

## How it is built

Everything is under `src/`, and the services in `config/services.php`.

| Directory | Holds |
|---|---|
| `AdminApi/` | The client, the JWT provider, payload helpers, the revert target |
| `AdminApi/Adapter/` | `Route`, `Resource`, the adapter contract and its base |
| `AdminApi/Line/` | `SyliusLineInterface`, `SyliusLines` |
| `AdminApi/Gate/` | The version and shop-extension gates and their attributes |
| `AdminApi/Command/` | The two check commands |
| `Tool/<Resource>/` | The MCP Tools, one class per tool, plus item schemas and builders |
| `Tool/Shared/` | Traits and helpers shared by tools (`Batch/`, references, failures) |
| `Output/<Resource>/` | The output DTOs |
| `File/` | The file argument of the upload tools |
| `Testing/` | `McpToolTestCase` and the conformance test cases |
| `Command/` | `AgentScenarioCommand` (`mcp:bench:agent`) |
| `DependencyInjection/Compiler/` | `ShopExtensionsPass` |

### A read, end to end

`Tool/TaxCategory/SearchTaxCategories.php` is the shortest example.

1. The SDK calls `__invoke()` with the arguments of the `tools/call`.
   The class is a service because `config/services.php` loads everything under `Tool/` by namespace, with autowiring, and the bundle prepends `Emagma\McpSyliusBundle\Tool\` to the MCP server's `registry.tools`.
2. The tool builds a `Route` (`Route::collection(Resource::TaxCategory)`) and calls `AdminApiClientInterface::getDecoded()`.
3. `AdminApiClient` asks the `SyliusApiAdapterInterface` of the shop's line for the path (`resolve()`), adds the bearer from `JwtTokenProviderInterface`, and sends the request on the scoped client `emagma_mcp_sylius.admin_api.http_client`.
4. The tool shapes the answer: `AdminPayload` for typed reads of the JSON-LD, an output DTO (`Output/…::fromPayload()`) for a detail, `PageRequest::envelope()` (core) for a page.

Before step 1, the core's authorization asks the tool gates: `ShopExtensionGate` (priority 10) then `SyliusVersionGate`.
A refusal hides the tool from `tools/list` and answers the call.

### A write, end to end

`Tool/Order/CancelOrder.php` shows a single write; `Tool/Product/UpsertProducts.php` a batch.

1. Read the current state (`getDecoded`), map a refusal with `ApiFailure::describe()`.
2. Compute the `diff`, render a preview.
3. `WriteConfirmationTrait::confirmWrite()` (core) elicits the human or returns a refusal the tool merges into its answer.
   The trait is used by the tool class itself: the gate reads the tool's own `#[Permission]`.
4. Write (`put`, `post`, `patch`, `delete`, or their `*Many` variants).
5. `WriteJournalInterface::record(tool:, targetType:, targetCode:, beforeState:, afterState:, diff:, context:)`.
6. Answer `['ok' => true, '<verb>' => true, …, 'diff', 'before', 'after']`.

Batch tools use `Tool/Shared/Batch/BatchUpsertTrait` (`runUpsert()`) or `BatchDeleteTrait` (`runDelete()`), which do the probe, one confirmation for the batch, the concurrent writes and the per-item envelope.
The tool supplies the abstract methods: `targetType()`, `label()`, `validateItem()`, `diffFor()`, `postBody()`, `putBody()` for an upsert; `targetType()`, `label()`, `nameOf()` for a delete.
An upsert keyed on personal data overrides `auditCode()`, so the audit records the id: `UpsertCustomers` and `UpsertAdministrators` are keyed on the email.

The `targetCode` is stored as given and read back by whoever reads the audit: never an email (`SinkWriteJournal` fails a test that records one).
A tool whose change no snapshot written back undoes - a state transition, an email sent, a file replaced - carries the core's `#[Irreversible]` (a `delete` tool is irreversible without it); `IrreversibleToolsTest` checks the transition verbs and the descriptions that say IRREVERSIBLE or not revertable.

### The client

`AdminApi/AdminApiClient.php` implements `AdminApiClientInterface`:

- A `Route` goes through the adapter (`path()` and, for a body, `body()`); a string path is taken as is.
- On a 401 or 403 it invalidates the token and tries again with a fresh one.
- `getDecodedMany()`, `putMany()`, `postMany()`, `deleteMany()` are concurrent, keep the input order and report a failure under its key instead of raising.
- `patch()` sets the merge-patch content type itself.
- The first response is checked for the `Sylius-Mcp-Plugin` header; without it a warning is logged once on the `emagma_mcp_sylius` channel.

`JwtTokenProvider` logs in at `administrators/token` with the service account and keeps the token in `cache.app` (key per account, expiry = the token's `exp` minus 30 seconds), so the PHP processes share one authentication.

### Routes and lines

- `Resource` (enum, implements `ApiResource`) declares where each Sylius resource lives **in the default layout**, which is the layout of 2.x: `'products/{product}/images/{id}'`.
  The last placeholder is the key.
- `Route` says where a call goes without a path: `Route::collection()`, `::item()`, `::action()`, `::collectionAction()`, then `withKey()` and `withQuery()`.
- `AbstractSyliusApiAdapter` builds the path from the templates.
  A line's adapter overrides only what differs, in three constant tables: `ITEMS`, `COLLECTIONS` (key `resource` or `resource METHOD`), `ACTIONS` (key `resource action`).
- `SyliusLines` collects the services tagged `emagma_mcp_sylius.line`, indexes them by minor version and is the factory of both `SyliusVersion` (`configured()`) and `SyliusApiAdapterInterface` (`adapter()`).
  Two lines for one version: `LogicException`.
  No line for the configured version: `InvalidArgumentException` when the service is first built.
- `DefaultLayoutAdapter` is the default layout as an adapter, for logs (`Route::__toString()`) and tests.

### Personal data

- The output DTOs mark their fields with the core's attributes (`#[Personal]`, `#[Untrusted]`, `#[Identifier]`, `#[Surplus]` of `Emagma\McpCoreBundle\Output`).
- `SyliusSources::outputs()->personalKeys()` scans them once, at container build, into `SyliusSensitiveKeys` (tag `emagma_mcp_core.sensitive_keys`), which adds the keys only raw payloads carry (`emailcanonical`, `usernamecanonical`, `customerip`, `notes`) and declares the gateway configuration (`gatewayconfig.config`) an opaque subtree.
- `Tool/Shared/RevealTrait` implements `reveal: true` for a `get_*` tool marked `#[RevealsPersonalData]`.

### What the bundle gives the core

| Core contract | Implementation |
|---|---|
| `RevertTargetInterface` | `AdminApi/AdminApiRevertTarget` |
| `SubjectReferencesInterface` | `AdminApi/SyliusSubjectReferences` |
| `SensitiveKeysProviderInterface` | `AdminApi/SyliusSensitiveKeys` |
| `ToolGateInterface` (x2) | `Gate/ShopExtensionGate`, `Gate/SyliusVersionGate` |

`AdminApiRevertTarget` locates a snapshot by its `@id` (null for an IRI outside the Admin API) and writes it back with a `PUT`.

## Extension points

### `SyliusLineInterface` - a Sylius line (tag `emagma_mcp_sylius.line`)

Autoconfigured: implementing the interface is enough when the service is autoconfigured; the line bundles tag it by hand.

```php
use Emagma\McpSyliusBundle\AdminApi\Adapter\AbstractSyliusApiAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLineInterface;

final readonly class Sylius3Adapter extends AbstractSyliusApiAdapter
{
    public const array ITEMS = [
        'province' => 'provinces/{code}',
    ];
}

final readonly class Sylius3Line implements SyliusLineInterface
{
    public function versions(): array
    {
        return ['3.0'];
    }

    public function adapter(string $apiUrl): SyliusApiAdapterInterface
    {
        return new Sylius3Adapter($apiUrl);
    }
}
```

An adapter may also override `body(Route $route, string $method, array $body): array` when a line wants in the body what another reads off the URI.

### `ApiResource` - the routes of a shop extension

A shop extension declares its own resources; `Route` and the client accept any `ApiResource`.

```php
use Emagma\McpSyliusBundle\AdminApi\Adapter\ApiResource;

enum WishlistResource: string implements ApiResource
{
    case Wishlist = 'wishlist';

    public function routeKey(): string
    {
        return $this->value;
    }

    public function itemTemplate(): ?string
    {
        return 'wishlists/{id}';
    }

    public function collectionTemplate(): ?string
    {
        return null; // the item's template without its key
    }
}
```

### `#[RequiresShopExtension]` and the tag `emagma_mcp_sylius.shop_extension`

The attribute hides a tool unless its extension is listed in `MCP_SHOP_EXTENSIONS`:

```php
use Emagma\McpSyliusBundle\AdminApi\Gate\RequiresShopExtension;

#[RequiresShopExtension('wishlist')]
final readonly class SearchWishlists
{
}
```

The extension's bundle declares the name with a tag on any of its services, or `ShopExtensions` rejects the name as unknown:

```php
->tag('emagma_mcp_sylius.shop_extension', ['extension' => 'wishlist'])
```

`ShopExtensionsPass` throws when the tag has no `extension` attribute.

### `#[RequiresApiFeature]` and `ApiFeature`

For a tool whose endpoint not every supported line has.
Repeatable: every feature named must be available.

```php
use Emagma\McpSyliusBundle\AdminApi\ApiFeature;
use Emagma\McpSyliusBundle\AdminApi\Gate\RequiresApiFeature;

#[RequiresApiFeature(ApiFeature::PaymentRefund)]
final readonly class RefundPayment
{
}
```

`ApiFeature` has two cases: `PaymentRefund` (since 2.0) and `ZonePriority` (since 2.2).
A feature that is one field of a tool is tested in the tool instead, as `UpsertZones` does:

```php
ApiFeature::ZonePriority->isAvailableOn($this->syliusVersion)
```

### `AdminApiClientInterface` - calling the shop from another bundle

```php
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;

final readonly class OrderReader
{
    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /** @return array<string, mixed> */
    public function order(string $tokenValue): array
    {
        return $this->client->getDecoded(Route::item(Resource::Order, $tokenValue));
    }
}
```

### `JwtTokenProviderInterface` - another identity towards the shop

Aliased to `JwtTokenProvider`.
A host may decorate it to carry a per-user identity instead of the service account.

```php
interface JwtTokenProviderInterface
{
    public function getToken(): string;

    public function invalidate(): void;
}
```

### `ToolSources` and `OutputCatalog` - where the tools and DTOs of a bundle live

Used by the conformance tests and scripts, without the registry.
A bundle that brings tools exposes its own, as `SyliusSources` does:

```php
use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\ToolSources;

final class WishlistSources
{
    public static function tools(): ToolSources
    {
        return ToolSources::of(__DIR__ . '/Tool', 'Acme\Wishlist\Tool\\', 'Extension/Wishlist/');
    }

    public static function outputs(): OutputCatalog
    {
        return OutputCatalog::of(__DIR__ . '/Output', 'Acme\Wishlist\Output\\');
    }
}
```

The application's `ServerSources` assembles them with `->with(...)`.

### `File\FileInput` - a file argument

`resolve(?string $base64Content, ?array $file, ?string $filename, int $maxBytes): ResolvedFile`, with `FileInput::SCHEMA`, `::META` and `::DESCRIPTION` for the tool's declaration.
A failure is a `FileInputFailure` whose `$failure` is the tool's answer without the tool's own keys.

## Configuration reference

Declared in `EmagmaMcpSyliusBundle::configure()`.

| Key | Default | Becomes |
|---|---|---|
| `api_url` | `%env(SYLIUS_API_URL)%` | parameter `emagma_mcp_sylius.api_url` |
| `sylius_version` | `%env(MCP_SYLIUS_VERSION)%` | parameter `emagma_mcp_sylius.sylius_version` |
| `shop_extensions` | `%env(MCP_SHOP_EXTENSIONS)%` | parameter `emagma_mcp_sylius.shop_extensions` |
| `default_locale` | `%env(MCP_DEFAULT_LOCALE)%` | parameter `emagma_mcp_sylius.default_locale` |
| `service_account.email` | `%env(MCP_SERVICE_ACCOUNT_EMAIL)%` | parameter `emagma_mcp_sylius.service_account.email` |
| `service_account.password` | `%env(MCP_SERVICE_ACCOUNT_PASSWORD)%` | parameter `emagma_mcp_sylius.service_account.password` |
| `bench` | `false` | registers the bench command |
| `file_params.allowed_hosts` | `EmagmaMcpSyliusBundle::DEFAULT_FILE_HOSTS` | parameter `emagma_mcp_sylius.file_params.allowed_hosts` |

All but `shop_extensions`, `bench` and `file_params` are `cannotBeEmpty()`.

What `loadExtension()` does beyond the parameters:

- registers `SyliusLineInterface` for autoconfiguration with the tag `emagma_mcp_sylius.line`;
- imports `config/services.php`, which binds `string $defaultLocale` and `string $serviceAccountEmail` for every tool;
- fills `emagma_mcp_sylius.sensitive_keys` with `SyliusSources::outputs()->personalKeys()`;
- with `bench: true`, decorates `emagma_mcp_sylius.admin_api.http_client` with a `TraceableHttpClient` and registers `AgentScenarioCommand`.

What `prependExtension()` adds to the configuration of other bundles (a host overrides key by key):

- **`emagma_mcp_core.permission_domains`**: `Emagma\McpSyliusBundle\` to `sylius`, hence the `mcp:sylius:...` grants.
- **`framework.http_client.scoped_clients`**:
  - `emagma_mcp_sylius.admin_api.http_client`: `base_uri` = `api_url`, `timeout` 10, `max_duration` 30, `Accept` and `Content-Type` `application/ld+json`;
  - `emagma_mcp_sylius.file_download.http_client`: scope `^https://`, `timeout` 10, `max_duration` 30, `max_redirects` 0.
    It is wrapped in a `NoPrivateNetworkHttpClient`.
- **`emagma_mcp_core.untrusted_content_notice`**: the shop's notice about customer-written text.
- **The MCP server the core attaches to** (`McpCoreConfig::prependServerConfig()`): `registry.tools` = `Emagma\McpSyliusBundle\Tool\`.
- **The log channel** `emagma_mcp_sylius`, when Monolog is there.
- **The parameter `emagma_mcp_sylius.audit`**: whether the `emagma_mcp_audit` extension is loaded, known for certain in `prependExtension()` only.

`emagma_mcp_sylius.instruction` (tag `emagma_mcp_core.instruction`, priority 100) gives `ServerInstructions::text()` first in the server's instructions.
Without the audit, the sentences about `revert_mcp_edit` and `batchId` are left out.

`ShopExtensionsPass` (added in `build()`) gives `emagma_mcp_sylius.shop_extensions` the names declared with the tag `emagma_mcp_sylius.shop_extension`.

### Service ids

Every service of the bundle is named `emagma_mcp_sylius.<name>`, and wired explicitly, but the tools.
The class or interface is an alias of that id: inject `AdminApiClientInterface`, `FileInput`, `SyliusVersion` or `ShopExtensions` by type.
A host re-aliases an interface (`JwtTokenProviderInterface`) rather than a service id.

## Data

No entity, no table, no migration.

- **Cache**: the service account's JWT in `cache.app`, key `emagma_mcp_sylius.admin_api.jwt.<sha1 of the email>`.
- **Files**: `mcp:bench:agent` writes its trace under `var/bench/` of the host application (JSON and Markdown).
- **Audit**: tools record through the core's `WriteJournalInterface`; the table belongs to the audit bundle.

## Tests and quality

`tests/Unit/`:

| Directory | Covers |
|---|---|
| `Tool/<Resource>/` | Each tool, on a mocked client |
| `Tool/Shared/` | The batch traits, references, failures, translations |
| `Tool/Conformance/` | Forged confirmations, output field marks |
| `Conformance/` | The conventions, on this bundle's tools and DTOs |
| `AdminApi/` | Client, adapters, lines, gates, commands, version |
| `Output/`, `File/`, `DependencyInjection/` | DTOs, file input, the bundle class |

`tests/Integration/` boots a compiled kernel with the core, the MCP server, this bundle and a line of its own: every tool of the bundle is on the server.

### `Testing/McpToolTestCase`

The harness of a tool unit test, shipped for the bundles that bring tools.
No I/O.

| Helper | Gives |
|---|---|
| `call($tool, ...$arguments)` | The result as an MCP client receives it (through JSON) |
| `wire($result)` | The same for any value |
| `apiClient()` | A mock of `AdminApiClientInterface` |
| `adapter()` | A `DefaultLayoutAdapter` |
| `grants(...$grants)` | A stub `GrantsProviderInterface` |
| `audit()` / `auditSpy()` | A journal; the journal and its mocked sink |
| `fileInput($download)` | A `FileInput` over a mock HTTP client |
| `collection($members, $total)` | A Hydra collection payload |
| `clientException($status, $violations)` | A `ClientException` with violations |
| `redactor()` | A `SensitiveDataRedactor` with the Sylius keys |

It also uses the core's `ElicitationTestTrait`.

```php
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Address\UpdateAddress;

final class UpdateAddressTest extends McpToolTestCase
{
    public function testWithoutAConfirmationTheChangeIsPreviewedNotWritten(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['@id' => '/api/v2/admin/addresses/12', 'street' => '21 rue de la Paix']);
        $client->expects(self::never())->method('put');

        $result = $this->call(new UpdateAddress($client, $this->audit()), '12', street: '12 rue de la Paix');

        self::assertSame('elicitation_unavailable_preview_only', $result['reason']);
    }
}
```

### `Testing/Conformance/*TestCase`

Abstract test cases: a bundle that brings tools extends them in its own `tests/Unit/Conformance` and returns its `ToolSources` / `OutputCatalog`; a host replays them on everything its sources assemble.

| Test case | Rule |
|---|---|
| `ApiPathConventionsTestCase` | No tool builds an Admin API path or IRI prefix by hand |
| `ToolResultEnvelopeTestCase` | Every write result literal carries `ok`, no read result does |
| `ListToolsOptInToLeanTestCase` | Every list tool uses `LeanLocaleHeaderTrait` |
| `NullableArgumentsAcceptNullTestCase` | Every nullable argument accepts an explicit null |
| `RevealCoverageTestCase` | A `get_*` that renders identifiers takes `reveal`; no `search_*` does |
| `BatchUpsertUntrustedDiffTestCase` | A batch upsert with untrusted fields names its output DTO |
| `FieldRegistryCoverageTestCase` | Every output DTO follows the field registry rules |

```php
use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\Testing\Conformance\ApiPathConventionsTestCase;
use Emagma\McpSyliusBundle\ToolSources;

final class ApiPathConventionsTest extends ApiPathConventionsTestCase
{
    protected static function tools(): ToolSources
    {
        return SyliusSources::tools();
    }
}
```

This bundle's `tests/Unit/Conformance` also extends the core's cases (`ToolPermissionCoverageTestCase`, `ToolAnnotationsCoverageTestCase`, `MutatingToolsAreGatedTestCase`, `IrreversibleToolsTestCase`).

### Running

Alone, on its own dependencies:

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

Inside an application that installs the bundle, with the application's tools and the files of the package:

```bash
vendor/bin/phpunit -c vendor/emagma/mcp-sylius-bundle/phpunit.xml.dist
vendor/bin/ecs check --config vendor/emagma/mcp-sylius-bundle/ecs.php
```

PHPStan runs at level max with a baseline (`phpstan-baseline.neon`).

## Traps

- **Never write a path by hand in a tool**: use `Route` and `Resource`.
  A literal path works on 2.x and breaks 1.14; `ApiPathConventionsTestCase` fails on it.
- **Everything under `Tool/` is a service**: `config/services.php` has no exclusion list, and a DTO placed there would fail the container build.
  Output DTOs go in `Output/`.
- **A list tool must send `Prefer: respond-for=lean`** (`LeanLocaleHeaderTrait`): the scoped client sends none, and without it the plugin's filters do not act and the shop answers the whole collection.
- **`#[Schema]` on a nullable parameter replaces the inferred type**: `type: 'array'` or an `enum` without null makes an explicit null invalid, and OpenAI models send every property.
  Put the schema on the method with `'type' => ['object', 'null']`, as `SearchProducts` does.
- **Options are `%env()%` by default**: the bundle sets parameters, never constructor literals, so one compiled container serves every shop.
  The base URI of the Admin API client is the exception: the framework resolves it while it loads, before this bundle sets its parameters, so `prependExtension()` reads `api_url` from the raw configuration (the last entry wins, else the `%env()%` default).
- **An unsupported version or an unknown extension fails on the first request, not at build**: `SyliusVersion` and `ShopExtensions` are built from runtime values.
- **`put()` is not partial**: the body carries the whole resource.
  `UpdateAddress` reads the address and sends back what was not given.
- **A revert does not undo a state transition**: `state` is outside Sylius' update groups, so writing a snapshot back leaves it.
  Mark the tool `#[Irreversible]`: its entries are refused before the shop is read.
- **The tests of this bundle never name a line's adapter**: use `DefaultLayoutAdapter`, which `McpToolTestCase::adapter()` returns.
- **A new 2.x minor** goes in `Sylius2Line::VERSIONS` once its routes are checked (the route table at the end of this guide); a new major is a new bundle.
- **A host that counts its tools** fails when a scan shrinks: moving or removing tools means updating its floor.

## Before you expose the server

Two decisions belong to this bundle.
The overview of the others is in the developer guide of `emagma/mcp-auth-bundle`.

### The service account

- **What it does**: every call to the Admin API carries the JWT of one Sylius administrator (`service_account.email` / `.password`, from `MCP_SERVICE_ACCOUNT_*`), whoever the MCP caller is.
  `JwtTokenProvider` keeps the token in `cache.app` until 30 seconds before it expires.
  The caller's identity stays in the server: it decides the grants and is the actor of the audit.
- **In the shop**: the plugin's `sylius-mcp:service-account` command creates the account with `ROLE_API_ACCESS` only, what the Admin API firewall asks for.
  `ROLE_ADMINISTRATION_ACCESS` is withheld (and removed from an account created by an older version): the account cannot open the back office.
  See the user guide of `emagma/sylius-mcp-plugin`.
- **Consequences**:
  - Sylius's own history (the price history, for example) credits every change to the service account.
    The server's audit is the record of who asked.
  - `upsert_administrators` refuses to disable this account or to change its password, and `delete_administrators` refuses to delete it (`service_account_protected`): the server would cut off its own access with no way to restore it.
  - The password is a secret of the server and of the shop, and of nobody else.
- **What to set**: an account dedicated to the server, with a password of its own passed through the environment.
  `JwtTokenProviderInterface` is the extension point for a per-user identity towards the shop instead.

### Files downloaded from the client

- **What it does**: `create_product_image` and `create_taxon_image` (and `upload_cms_media` of the CMS bundle) accept, instead of `base64Content`, a `file` object `{download_url, file_id, …}`: the file a client uploaded for the user (ChatGPT fills it in, `openai/fileParams`), which the server downloads.
  `RemoteFileDownloader` fetches only `https`, to a host of `file_params.allowed_hosts`, without following redirects, through a `NoPrivateNetworkHttpClient` (no private, loopback or link-local address), and stops past the tool's size cap.
  The type is read from the bytes, not taken from the client.
  The `download_url` is a temporary bearer: it is never logged, recorded in the audit or returned to the model.
- **Default**: `oaiusercontent.com` and `oaisdmntpr*.blob.core.windows.net`, the hosts of ChatGPT's file stores (its regional storage accounts, such as `oaisdmntprnortheu`).
  A plain name matches itself and its subdomains; a pattern with `*` matches the whole host; `*` alone allows any host.
- **Risk**: `download_url` is a tool argument, written by the model, and under a trusted client no person stands between the model and the call.
  Without the list, the server could be made to fetch what only it can reach (server-side request forgery).
  The private-network guard covers addresses; the list also keeps out public hosts you did not choose.
- **What to set**: keep the list, or name your client's file store.
  A refusal names the host (`file_url_refused`, `host`): if the client moves its store, that is the value to add.
  A host that serves ChatGPT may set `['*']`, because its regional stores change with its datacenters; what still holds then is `https` only, no redirect, no private address, the size cap and the type read from the bytes.

## Appendix: the Admin API routes of each Sylius line

What the line bundles' adapters are built on: the routes each Sylius line exposes under `/api/v2/admin`, read from the API Platform configuration of each Sylius tag (sparse git clones of `Sylius/Sylius`), not from the upgrade notes, which miss or contradict a few routes.
The table at the end is regenerated by `castor mcp:sylius-api-routes` (`.castor/sylius-api-routes.php`): add a tag to `TAGS` when a line ships, and check the differences before adding the line to `Sylius2Line::VERSIONS` or writing a new line bundle.
The notes before the table are written by hand.

| Line | Source | api-platform constraint (root composer.json / ApiBundle composer.json) | PHP |
|---|---|---|---|
| 1.14 | tag `v1.14.20` (latest 1.14.x) | `api-platform/core: ^2.7.10` (conflict pins `2.7.17` - i.e. APIP 2.7 only, legacy metadata XML `metadata-2.0.xsd`) | ^8.1 |
| 2.0 | tag `v2.0.0` | `api-platform/doctrine-orm: ^4.0.3`, `api-platform/symfony: ^4.0.3` (resources XML `resources-3.0.xsd`) | ^8.2 |
| 2.1 | tag `v2.1.0` | `api-platform/doctrine-orm: ^4.1.7`, `api-platform/symfony: ^4.1.7` | ^8.2 |
| 2.2 | tag `v2.2.0` | `api-platform/doctrine-orm: ^4.2.1`, `api-platform/state: ^4.2.1`, `api-platform/symfony: ^4.2.1` | ^8.2 |
| 2.3 | tag `v2.3.0` | `api-platform/doctrine-orm: ^4.3 \|\| ^5.0`, `api-platform/state: ^4.3 \|\| ^5.0`, `api-platform/symfony: ^4.3.17 \|\| ^5.0` (ApiBundle also `api-platform/metadata: ^4.2.1 \|\| ^5.0`); installed in the local shop: `api-platform/symfony` v4.4.4 (its `Configuration.php` defaults `hydra_prefix` to `false`) | ^8.3 |

Files read per tag: `src/Sylius/Bundle/ApiBundle/Resources/config/{api_resources/*.xml (1.14) | api_platform/resources/admin/*.xml (2.x)}`, `serialization/*.xml`, `app/config.yaml`, `routing.yml`, `Serializer/**`, root and bundle `composer.json`; `tests/Api/Admin` of 1.14 and 2.0 and the local shop's `var/cache/*/url_generating_routes.php` for verification of derived paths; `CoreBundle/Resources/config/doctrine/model/ProductVariant.orm.xml` (all lines) for `channelPricings` keying.
Everything requested was fetched; nothing is marked unfetchable.
Extraction and table, with the computed diff: `castor mcp:sylius-api-routes` (`.castor/sylius-api-routes.php`, PHP DOM).

Scope of "identical" claims below: method + path template, normalization/denormalization group names and input class of every admin operation.
NOT covered: filters/query parameters, property-level group membership (see "Serialization" for the property deltas that were spotted), validation groups, OpenAPI metadata.

### Differences between lines

#### 1.14 -> 2.0 (the only line boundary with route changes)

Route *moves* (same capability, new template; the old one is gone):

- Promotion coupons: `/admin/promotion-coupons[/generate|/{code}]` (GET/POST/PUT/DELETE, POST generate) -> `/admin/promotions/{promotionCode}/coupons[/generate|/{couponCode}]`.
  The 1.14 subresource `GET /admin/promotions/{code}/coupons` survives as the 2.x collection (variable renamed `{code}` -> `{promotionCode}`).
- Product images: `/admin/product-images[/{id}]` (GET collection, GET/PUT/DELETE item) -> `/admin/products/{code}/images[/{id}]`; `POST /admin/products/{code}/images` and the collection existed in both.
- Taxon images: `/admin/taxon-images[/{id}]` -> `/admin/taxons/{code}/images[/{id}]` (same pattern as product images).
- Provinces: `GET/PUT /admin/provinces/{code}` -> `/admin/countries/{countryCode}/provinces/{provinceCode}`; `GET /admin/countries/{code}/provinces` exists in both.
- Product option values: `GET /admin/product-option-values/{code}` -> `GET /admin/product-options/{optionCode}/values/{code}`; `GET /admin/product-options/{code}/values` in both.
- Administrator avatar: `POST /admin/avatar-images`, `GET/DELETE /admin/avatar-images/{id}` -> `GET/POST/DELETE /admin/administrators/{id}/avatar-image` (singular, one per admin).
- Channel pricing item: `GET /admin/channel-pricings/{id}` (1.14: routable, hidden from docs via `sylius.api.paths_to_hide`) -> `GET /admin/product-variants/{code}/channel-pricings/{channelCode}` declared `NotExposed` (IRI only, answers 404).
- Promotion rules/actions, catalog promotion scopes/actions, shipping method rules, gateway configs: 1.14 flat `GET /admin/{promotion-rules|promotion-actions|catalog-promotion-scopes|catalog-promotion-actions|shipping-method-rules|gateway-configs}/{id}` (routable, hidden from docs) -> 2.x `GET /admin/promotions/{code}/rules/{id}`, `/admin/promotions/{code}/actions/{id}`, `/admin/catalog-promotions/{code}/scopes/{id}`, `/admin/catalog-promotions/{code}/actions/{id}`, `/admin/shipping-method-rules/{id}`, `/admin/gateway-configs/{id}`, all `NotExposed` (404).
- Translations: 1.14 flat `GET /admin/{product|product-variant|product-option|product-attribute|product-association-type|taxon|promotion|catalog-promotion|payment-method|shipping-method}-translations/{id}` -> 2.x nested `GET /admin/<parent>/{code}/translations/{localeCode}` (plus `/admin/product-option-values/{code}/translations/{localeCode}`, new), ALL `NotExposed` (404).
  Quirk verbatim in 2.0 and 2.3: `/admin/taxon/{code}/translations/{localeCode}` (singular `taxon`).
  The 2.x operation names end in `_get_collection` although they are item IRIs; irrelevant to clients since they 404.
- `GET /admin/payment-methods/{code}/gateway-config` and `GET /admin/channels/{code}/shop-billing-data` (1.14 subresources) and `GET /admin/shop-billing-datas/{id}`: removed; in 2.x they are embedded in the parent payload (verified: 2.0 `serialization/GatewayConfig.xml` carries only `sylius:admin:payment_method:{index,show,create,update}`, `ShopBillingData.xml` only `sylius:admin:channel:{index,show,create,update}`).
- Zone members: 1.14 `GET /admin/zones/{code}/members` (derived subresource, used by `tests/Api/Admin/ZonesTest`) and `GET /admin/zone-members/{id}`: removed in 2.x, members embedded in the zone (2.0 `ZoneMember.xml` groups: `sylius:admin:zone:{index,show,create,update}`).
- `GET/PUT /admin/channel-price-history-configs/{id}`: removed in 2.x, embedded in the channel (2.0 `ChannelPriceHistoryConfig.xml` groups: `sylius:admin:channel:{index,show,create,update}`; the `ChannelPriceHistoryConfigDenormalizer` is still there).

Added in 2.0 (no 1.14 counterpart): `PATCH /admin/payments/{id}/refund`; `GET /admin/payment-requests/{hash}`; `GET /admin/payments/{paymentId}/payment-requests`; `GET /admin/address-log-entries/{id}` (`NotExposed`); `GET /admin/addresses/{id}/log-entries` existed in 1.14 as an item operation and is a resource-level collection in 2.x (same template).

Unchanged across 1.14 -> 2.3 (same method + template): products, product variants (and `/admin/product-variants/{code}` sub-templates), product options/values collections, product attributes (+ values), taxons, product taxons, product associations (+ types), product reviews (+ accept/reject), orders (`/admin/orders`, `/{tokenValue}`, `PATCH /cancel`, `POST /resend-confirmation-email`, `/payments`, `/shipments`, `/adjustments`, `/admin/order-items/{id}`, `/admin/order-item-units/{id}`, their `/adjustments`), payments (`/admin/payments[/{id}][/complete]`), shipments (`/admin/shipments[/{id}]`, `PATCH /ship`, `POST /resend-confirmation-email`, `/adjustments`), customers (`/admin/customers[/{id}][/statistics|/user]`), customer groups (`{code}`), addresses, administrators (+ `POST /reset-password`, `PATCH /reset-password/{token}`, and the plain Symfony `POST /admin/administrators/token`), promotions (`{code}`, `/archive`, `/restore`), catalog promotions, channels, currencies, locales, countries, zones, exchange rates, payment methods, shipping methods (+ `/archive`, `/restore`), shipping categories, tax categories, tax rates, channel pricing log entries, `GET /admin/statistics` (plain Symfony route, controller `sylius_api.controller.get_statistics`).

1.14 source quirks: `Locale.xml` line 77 declares `DELETE` with path `admin/locales/{code}` (no leading slash; Symfony normalises it, treated as `/admin/locales/{code}` here). 1.14 derived item paths use the identifier property (`{code}` when `<property name="code" identifier="true">`, else `{id}`); 1.14 default collection name for `ShopBillingData` is `/admin/shop-billing-datas` (confirmed by `tests/Api/Admin`).
The only 1.14 `[default]` path with no corroboration outside the XML is `GET /admin/product-option-translations/{id}` (not in `tests/`, not in `paths_to_hide`): derived from `route_prefix=admin` + shortName `ProductOptionTranslation` + identifier `id`, same rule as the verified siblings.

#### 2.0 -> 2.1, 2.1 -> 2.2, 2.2 -> 2.3

Zero admin route changes at every tier (exact template, masked template, groups, input class).
File-level, the only diffs under `api_platform/resources/admin/` are `Taxon.xml` (2.2 adds `urlGenerationStrategy="1"` on the resource = `UrlGeneratorInterface::ABS_PATH`, which is already APIP's default: no IRI format change for clients) and `AdminUserPassword.xml` (2.3 replaces the hard-coded `ResetPassword` command class by the parameter `%sylius_api.command.admin.account.reset_password.class%`).
The `sylius_cms` plugin routes are outside this table.

### Serialization groups

- 1.14: every operation lists two group names side by side, the legacy unprefixed one and the prefixed one: `admin:product:index` + `sylius:admin:product:index`, `admin:product_variant:show` + `sylius:admin:product_variant:show`, `admin:order:show` + `sylius:admin:order:show`, `admin:zone:update` + `sylius:admin:zone:update`, etc. ChannelPricing has no groups of its own: it is serialized through the `sylius:admin:product_variant:{index,show,create,update}` groups (same in every line). 1.14 ProductOptionValue uses `admin:product_option_value:write`.
- 2.0: only `sylius:admin:<resource>:{index,show,create,update}` remain (legacy names dropped).
  `sylius:admin:product_option_value:index` appears, `admin:product_option_value:write` disappears.
  Operation names become `sylius_api_admin_<resource>_<get|get_collection|post|put|patch|delete>[_<action>]` (from `admin_get` etc.), which matters for `sylius_api.filter_eager_loading_extension` and the `allowed_operations` parameters.
- 2.1: adds shop-only groups (`sylius:shop:product:index:default_variant`, `sylius:shop:product:show:default_variant`, `sylius:shop:product_option:index`, `sylius:shop:product_option_value:index`, `sylius:shop:order:show`); admin group names unchanged; `Order.checkoutCompletedAt` added to `sylius:admin:order:{index,show}`.
- 2.2: admin group names unchanged; properties added: `OrderItem.variantName` (admin order index/show, order_item show), `Zone.priority` (zone create/index/show/update), Taxon `level` and taxon-tree shop groups.
- 2.3: admin group names unchanged; properties added: `Promotion.trackUsage`, `PromotionCoupon.trackUsage` + `trackUsageSince`, `ShippingMethod.minDeliveryTimeDays` + `maxDeliveryTimeDays`, `AdminUser.administrationAccess` + `apiAccess`, `Order.orderAndItemPromotionTotal` (admin order index/show); `Order.itemsSubtotal` stays (2.2 declared it twice, 2.3 keeps one declaration with the same groups).

### Hydra prefix

- 1.14: APIP 2.7, no `hydra_prefix` option exists; responses always carry the `hydra:` prefix (`hydra:member`, `hydra:totalItems`, `hydra:title`, `hydra:description`).
- 2.0, 2.1, 2.2, 2.3: `api_platform.serializer.hydra_prefix: true` set explicitly in `Resources/config/app/config.yaml` (the installed `api-platform/symfony` v4.4.4 `Configuration.php` defaults it to `false`): same prefixed wire format as 1.14 (`hydra:member`, `hydra:totalItems`, `hydra:Error`, confirmed on the local 2.3 shop above).

### channelPricings keying

Identical in every line: `ProductVariant.channelPricings` is a Doctrine collection with `index-by="channelCode"` (`CoreBundle/Resources/config/doctrine/model/ProductVariant.orm.xml` line 40 in v1.14.20, v2.0.0, v2.1.0, v2.2.0 and the local 2.3) so the output is an object keyed by channel code; on input `ProductVariantChannelPricingsChannelCodeKeyDenormalizer` takes the object key as `channelCode`, and throws `ChannelPricingChannelCodeMismatchException` (mapped to 422 in every line) when a `channelCode` inside the entry differs from its key.
The class moved from `Sylius\Bundle\ApiBundle\Serializer\` (1.14) to `Sylius\Bundle\ApiBundle\Serializer\Denormalizer\` (2.x) and gained `getSupportedTypes()`; the logic is byte-identical between 2.0 and 2.3.

### Error format and other `api_platform` config

- 1.14: no `defaults` block; errors flow through the APIP 2.7 Hydra error normalizer (`HydraErrorNormalizer` decorates `api_platform.hydra.normalizer.error`, gated on the `/api/v2` prefix) and the deprecated FOSRest `FlattenExceptionNormalizer`.
  Shape (APIP 2.7 standard behaviour, not visible in Sylius config): `@type: hydra:Error`, `hydra:title` / `hydra:description` (+ `violations` for 422), no RFC 7807 `title`/`detail`/`status`.
- 2.0 -> 2.3: `defaults.extra_properties.rfc_7807_compliant_errors: true`.
  Real 2.3 sample from the local shop (`GET /api/v2/shop/products/DOES-NOT-EXIST`): `application/ld+json` -> `{"@context":"/api/v2/contexts/Error","@id":"/api/v2/errors/404","@type":"hydra:Error","detail":"Not Found","status":404,"type":"/errors/404","hydra:description":"Not Found","hydra:title":"An error occurred"}`; `application/json` -> `{"title":"An error occurred","detail":"Not Found","status":404,"type":"/errors/404"}` (RFC 7807 keys, plus the `hydra:` keys only on JSON-LD).
  `standard_put: false` (PUT keeps the APIP 3 "merge" semantics, not full replacement), `normalization_context.skip_null_values: false`, `pagination_client_items_per_page: true`, `use_symfony_listeners: true`, `formats: jsonld + json`, `patch_formats: json = application/merge-patch+json`.
  Identical from 2.0 to 2.3.
- `exception_to_status` deltas: 2.0 collapses the per-entity `*CannotBeRemoved: 422` of 1.14 into `Sylius\Component\Core\Exception\ResourceDeleteException: 422` and adds `PaymentRequestNotFoundException`, `PaymentNotFoundException`, `PaymentMethodNotFoundException` -> 404 and `InvalidPaymentRequestPayloadException` -> 422; `OrderItemNotFoundException` (422 in 1.14) is unmapped in 2.0/2.1/2.2 (status then depends on the exception's own code - unverified here) and mapped to 404 in 2.3; 2.2 flips `Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException` 400 -> 422. 2.0 -> 2.1: no config change.
- Authentication: `POST /admin/administrators/token` is a plain Symfony route in every line (`routing.yml`); 2.x additionally sets `lexik_jwt_authentication.api_platform.check_path: "%sylius.security.api_route%/administrators/token"` (literally `/api/v2/administrators/token`, without `/admin` - the OpenAPI doc entry, not the route).
- 1.14 only: `sylius.api.paths_to_hide` hides `/admin/catalog-promotion-actions/{id}`, `/admin/catalog-promotion-scopes/{id}`, `/admin/channel-pricings/{id}`, `/admin/promotion-actions/{id}`, `/admin/promotion-rules/{id}`, `/admin/shipping-method-rules/{id}`, `/admin/shop-users/{id}` from the docs; the routes still answer. 2.x replaces this with `NotExposed` operations (404).

### Behaviour the routes do not show

- **TranslatableDenormalizer and relation IRIs.** On 2.0.x, 2.1.0-2.1.12 and 2.2.0-2.2.3, a POST whose body references a translatable resource by its IRI (a variant's `product`, a product's `mainTaxon` or `options`, a taxon's `parent`) answers 500 ("Cannot access offset of type string on string"): the denormalizer lacks the `is_array($data)` check that 2.1.13 and 2.2.4 added.
  A nested `{"@id": …}` is refused (400, "Nested documents … are not allowed").
  The plugin fixes it on those versions (`FixTranslatableDenormalizerPass`), verified on a sylius-standard 2.0.18 shop. 1.14 has the same code but API Platform 2.7 never hands it an IRI string: a product with its main taxon and a variant with its product are created there (verified on 1.14.20).
- **Fields of newer lines.** `Zone.priority` is 2.2+; `Promotion.trackUsage`, `PromotionCoupon.trackUsage`, `ShippingMethod.min/maxDeliveryTimeDays` and `AdminUser.administrationAccess/apiAccess` are 2.3+.
  An older line ignores them on write: a tool that writes one refuses it there (`upsert_zones` on `priority`).
- **1.14 payloads.** The writable attributes of every resource the tools write are those of 2.x (translations, zone members, gateway configs, billing data embedded), but for a coupon created at the root (`promotion-coupons`), whose promotion goes in the body. 1.14 reads carry `id`s on translations, option values and zone members, and lack `Order.checkoutCompletedAt`, `OrderItem.variantName`, `Promotion.archivedAt`.

<!-- generated by castor mcp:sylius-api-routes: do not edit below -->
## Route table (admin, method x path template, per line)

Markers: `[default]` = no explicit path/uriTemplate in the XML, derived from `route_prefix`/`routePrefix` + shortName + identifier (verified against `tests/Api/Admin` of the tag, the 1.14 `paths_to_hide` list or the local shop's router cache unless noted); `[resource-level]` = `uriTemplate` set on the `<resource>` element and inherited; `[subresource]` = APIP 2.7 `<subresourceOperation>`; `(GetCollection)` = APIP 3 operation class for the translation rows. **NotExposed** = APIP `NotExposed` operation: the route exists for IRI generation but answers 404 (`NotExposedAction`).


### Products

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/products` | `/admin/products` | `/admin/products` | `/admin/products` | `/admin/products` |
| POST | `/admin/products` | `/admin/products` | `/admin/products` | `/admin/products` | `/admin/products` |
| DELETE | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` |
| GET | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` |
| PUT | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` | `/admin/products/{code}` |

### Product variants

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/product-variants` | `/admin/product-variants` | `/admin/product-variants` | `/admin/product-variants` | `/admin/product-variants` |
| POST | `/admin/product-variants` | `/admin/product-variants` | `/admin/product-variants` | `/admin/product-variants` | `/admin/product-variants` |
| DELETE | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` |
| GET | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` |
| PUT | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` | `/admin/product-variants/{code}` |

### Channel pricings

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/channel-pricings/{id}` `[default]` | - | - | - | - |
| GET | - | `/admin/product-variants/{code}/channel-pricings/{channelCode}` **NotExposed (404)** | `/admin/product-variants/{code}/channel-pricings/{channelCode}` **NotExposed (404)** | `/admin/product-variants/{code}/channel-pricings/{channelCode}` **NotExposed (404)** | `/admin/product-variants/{code}/channel-pricings/{channelCode}` **NotExposed (404)** |

### Product options (+ values)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/product-option-values/{code}` | - | - | - | - |
| GET | `/admin/product-options` | `/admin/product-options` | `/admin/product-options` | `/admin/product-options` | `/admin/product-options` |
| POST | `/admin/product-options` | `/admin/product-options` | `/admin/product-options` | `/admin/product-options` | `/admin/product-options` |
| DELETE | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` |
| GET | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` |
| PUT | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` | `/admin/product-options/{code}` |
| GET | `/admin/product-options/{code}/values` `[subresource]` | `/admin/product-options/{code}/values` `[resource-level]` | `/admin/product-options/{code}/values` `[resource-level]` | `/admin/product-options/{code}/values` `[resource-level]` | `/admin/product-options/{code}/values` `[resource-level]` |
| GET | - | `/admin/product-options/{optionCode}/values/{code}` | `/admin/product-options/{optionCode}/values/{code}` | `/admin/product-options/{optionCode}/values/{code}` | `/admin/product-options/{optionCode}/values/{code}` |

### Product attributes (+ values)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/product-attribute-values/{id}` | `/admin/product-attribute-values/{id}` | `/admin/product-attribute-values/{id}` | `/admin/product-attribute-values/{id}` | `/admin/product-attribute-values/{id}` |
| GET | `/admin/product-attributes` | `/admin/product-attributes` | `/admin/product-attributes` | `/admin/product-attributes` | `/admin/product-attributes` |
| POST | `/admin/product-attributes` | `/admin/product-attributes` | `/admin/product-attributes` | `/admin/product-attributes` | `/admin/product-attributes` |
| DELETE | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` |
| GET | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` |
| PUT | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` | `/admin/product-attributes/{code}` |

### Taxons

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/taxon-images` | - | - | - | - |
| DELETE | `/admin/taxon-images/{id}` | - | - | - | - |
| GET | `/admin/taxon-images/{id}` | - | - | - | - |
| PUT | `/admin/taxon-images/{id}` | - | - | - | - |
| GET | `/admin/taxons` | `/admin/taxons` | `/admin/taxons` | `/admin/taxons` | `/admin/taxons` |
| POST | `/admin/taxons` | `/admin/taxons` | `/admin/taxons` | `/admin/taxons` | `/admin/taxons` |
| DELETE | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` |
| GET | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` |
| PUT | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` | `/admin/taxons/{code}` |

### Product taxons

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/product-taxons` | `/admin/product-taxons` | `/admin/product-taxons` | `/admin/product-taxons` | `/admin/product-taxons` |
| POST | `/admin/product-taxons` | `/admin/product-taxons` | `/admin/product-taxons` | `/admin/product-taxons` | `/admin/product-taxons` |
| DELETE | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` |
| GET | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` |
| PUT | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` | `/admin/product-taxons/{id}` |

### Product images

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/product-images` | - | - | - | - |
| DELETE | `/admin/product-images/{id}` | - | - | - | - |
| GET | `/admin/product-images/{id}` | - | - | - | - |
| PUT | `/admin/product-images/{id}` | - | - | - | - |
| GET | `/admin/products/{code}/images` `[subresource]` | `/admin/products/{code}/images` | `/admin/products/{code}/images` | `/admin/products/{code}/images` | `/admin/products/{code}/images` |
| POST | `/admin/products/{code}/images` | `/admin/products/{code}/images` | `/admin/products/{code}/images` | `/admin/products/{code}/images` | `/admin/products/{code}/images` |
| DELETE | - | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` |
| GET | - | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` |
| PUT | - | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` | `/admin/products/{code}/images/{id}` |

### Taxon images

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/taxons/{code}/images` `[subresource]` | `/admin/taxons/{code}/images` | `/admin/taxons/{code}/images` | `/admin/taxons/{code}/images` | `/admin/taxons/{code}/images` |
| POST | `/admin/taxons/{code}/images` | `/admin/taxons/{code}/images` | `/admin/taxons/{code}/images` | `/admin/taxons/{code}/images` | `/admin/taxons/{code}/images` |
| DELETE | - | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` |
| GET | - | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` |
| PUT | - | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` | `/admin/taxons/{code}/images/{id}` |

### Product associations (+ types)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/product-association-types` | `/admin/product-association-types` | `/admin/product-association-types` | `/admin/product-association-types` | `/admin/product-association-types` |
| POST | `/admin/product-association-types` | `/admin/product-association-types` | `/admin/product-association-types` | `/admin/product-association-types` | `/admin/product-association-types` |
| DELETE | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` |
| GET | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` |
| PUT | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` | `/admin/product-association-types/{code}` |
| GET | `/admin/product-associations` | `/admin/product-associations` | `/admin/product-associations` | `/admin/product-associations` | `/admin/product-associations` |
| POST | `/admin/product-associations` | `/admin/product-associations` | `/admin/product-associations` | `/admin/product-associations` | `/admin/product-associations` |
| DELETE | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` |
| GET | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` |
| PUT | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` | `/admin/product-associations/{id}` |

### Product reviews

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/product-reviews` | `/admin/product-reviews` | `/admin/product-reviews` | `/admin/product-reviews` | `/admin/product-reviews` |
| DELETE | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` |
| GET | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` |
| PUT | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` | `/admin/product-reviews/{id}` |
| PATCH | `/admin/product-reviews/{id}/accept` | `/admin/product-reviews/{id}/accept` | `/admin/product-reviews/{id}/accept` | `/admin/product-reviews/{id}/accept` | `/admin/product-reviews/{id}/accept` |
| PATCH | `/admin/product-reviews/{id}/reject` | `/admin/product-reviews/{id}/reject` | `/admin/product-reviews/{id}/reject` | `/admin/product-reviews/{id}/reject` | `/admin/product-reviews/{id}/reject` |

### Orders (+ items / adjustments / cancel / resend)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/adjustments/{id}` | `/admin/adjustments/{id}` | `/admin/adjustments/{id}` | `/admin/adjustments/{id}` | `/admin/adjustments/{id}` |
| GET | `/admin/order-item-units/{id}` | `/admin/order-item-units/{id}` | `/admin/order-item-units/{id}` | `/admin/order-item-units/{id}` | `/admin/order-item-units/{id}` |
| GET | `/admin/order-item-units/{id}/adjustments` `[subresource]` | `/admin/order-item-units/{id}/adjustments` `[resource-level]` | `/admin/order-item-units/{id}/adjustments` `[resource-level]` | `/admin/order-item-units/{id}/adjustments` `[resource-level]` | `/admin/order-item-units/{id}/adjustments` `[resource-level]` |
| GET | `/admin/order-items/{id}` | `/admin/order-items/{id}` | `/admin/order-items/{id}` | `/admin/order-items/{id}` | `/admin/order-items/{id}` |
| GET | `/admin/order-items/{id}/adjustments` `[subresource]` | `/admin/order-items/{id}/adjustments` `[resource-level]` | `/admin/order-items/{id}/adjustments` `[resource-level]` | `/admin/order-items/{id}/adjustments` `[resource-level]` | `/admin/order-items/{id}/adjustments` `[resource-level]` |
| GET | `/admin/orders` | `/admin/orders` | `/admin/orders` | `/admin/orders` | `/admin/orders` |
| GET | `/admin/orders/{tokenValue}` | `/admin/orders/{tokenValue}` | `/admin/orders/{tokenValue}` | `/admin/orders/{tokenValue}` | `/admin/orders/{tokenValue}` |
| GET | `/admin/orders/{tokenValue}/adjustments` | `/admin/orders/{tokenValue}/adjustments` `[resource-level]` | `/admin/orders/{tokenValue}/adjustments` `[resource-level]` | `/admin/orders/{tokenValue}/adjustments` `[resource-level]` | `/admin/orders/{tokenValue}/adjustments` `[resource-level]` |
| PATCH | `/admin/orders/{tokenValue}/cancel` | `/admin/orders/{tokenValue}/cancel` | `/admin/orders/{tokenValue}/cancel` | `/admin/orders/{tokenValue}/cancel` | `/admin/orders/{tokenValue}/cancel` |
| GET | `/admin/orders/{tokenValue}/payments` `[subresource]` | `/admin/orders/{tokenValue}/payments` `[resource-level]` | `/admin/orders/{tokenValue}/payments` `[resource-level]` | `/admin/orders/{tokenValue}/payments` `[resource-level]` | `/admin/orders/{tokenValue}/payments` `[resource-level]` |
| POST | `/admin/orders/{tokenValue}/resend-confirmation-email` | `/admin/orders/{tokenValue}/resend-confirmation-email` | `/admin/orders/{tokenValue}/resend-confirmation-email` | `/admin/orders/{tokenValue}/resend-confirmation-email` | `/admin/orders/{tokenValue}/resend-confirmation-email` |
| GET | `/admin/orders/{tokenValue}/shipments` `[subresource]` | `/admin/orders/{tokenValue}/shipments` `[resource-level]` | `/admin/orders/{tokenValue}/shipments` `[resource-level]` | `/admin/orders/{tokenValue}/shipments` `[resource-level]` | `/admin/orders/{tokenValue}/shipments` `[resource-level]` |

### Payments (+ complete / refund / payment requests)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | - | `/admin/payment-requests/{hash}` | `/admin/payment-requests/{hash}` | `/admin/payment-requests/{hash}` | `/admin/payment-requests/{hash}` |
| GET | `/admin/payments` | `/admin/payments` | `/admin/payments` | `/admin/payments` | `/admin/payments` |
| GET | `/admin/payments/{id}` | `/admin/payments/{id}` | `/admin/payments/{id}` | `/admin/payments/{id}` | `/admin/payments/{id}` |
| PATCH | `/admin/payments/{id}/complete` | `/admin/payments/{id}/complete` | `/admin/payments/{id}/complete` | `/admin/payments/{id}/complete` | `/admin/payments/{id}/complete` |
| GET | - | `/admin/payments/{paymentId}/payment-requests` `[resource-level]` | `/admin/payments/{paymentId}/payment-requests` `[resource-level]` | `/admin/payments/{paymentId}/payment-requests` `[resource-level]` | `/admin/payments/{paymentId}/payment-requests` `[resource-level]` |
| PATCH | - | `/admin/payments/{id}/refund` | `/admin/payments/{id}/refund` | `/admin/payments/{id}/refund` | `/admin/payments/{id}/refund` |

### Shipments (+ ship / resend)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/shipments` | `/admin/shipments` | `/admin/shipments` | `/admin/shipments` | `/admin/shipments` |
| GET | `/admin/shipments/{id}` | `/admin/shipments/{id}` | `/admin/shipments/{id}` | `/admin/shipments/{id}` | `/admin/shipments/{id}` |
| GET | `/admin/shipments/{id}/adjustments` `[subresource]` | `/admin/shipments/{id}/adjustments` `[resource-level]` | `/admin/shipments/{id}/adjustments` `[resource-level]` | `/admin/shipments/{id}/adjustments` `[resource-level]` | `/admin/shipments/{id}/adjustments` `[resource-level]` |
| POST | `/admin/shipments/{id}/resend-confirmation-email` | `/admin/shipments/{id}/resend-confirmation-email` | `/admin/shipments/{id}/resend-confirmation-email` | `/admin/shipments/{id}/resend-confirmation-email` | `/admin/shipments/{id}/resend-confirmation-email` |
| PATCH | `/admin/shipments/{id}/ship` | `/admin/shipments/{id}/ship` | `/admin/shipments/{id}/ship` | `/admin/shipments/{id}/ship` | `/admin/shipments/{id}/ship` |

### Customers (+ statistics / user)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/customers` | `/admin/customers` | `/admin/customers` | `/admin/customers` | `/admin/customers` |
| POST | `/admin/customers` | `/admin/customers` | `/admin/customers` | `/admin/customers` | `/admin/customers` |
| GET | `/admin/customers/{id}` | `/admin/customers/{id}` | `/admin/customers/{id}` | `/admin/customers/{id}` | `/admin/customers/{id}` |
| PUT | `/admin/customers/{id}` | `/admin/customers/{id}` | `/admin/customers/{id}` | `/admin/customers/{id}` | `/admin/customers/{id}` |
| GET | `/admin/customers/{id}/statistics` | `/admin/customers/{id}/statistics` | `/admin/customers/{id}/statistics` | `/admin/customers/{id}/statistics` | `/admin/customers/{id}/statistics` |
| DELETE | `/admin/customers/{id}/user` | `/admin/customers/{id}/user` | `/admin/customers/{id}/user` | `/admin/customers/{id}/user` | `/admin/customers/{id}/user` |
| GET | `/admin/shop-users/{id}` | `/admin/shop-users/{id}` **NotExposed (404)** | `/admin/shop-users/{id}` **NotExposed (404)** | `/admin/shop-users/{id}` **NotExposed (404)** | `/admin/shop-users/{id}` **NotExposed (404)** |

### Customer groups

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/customer-groups` `[default]` | `/admin/customer-groups` | `/admin/customer-groups` | `/admin/customer-groups` | `/admin/customer-groups` |
| POST | `/admin/customer-groups` `[default]` | `/admin/customer-groups` | `/admin/customer-groups` | `/admin/customer-groups` | `/admin/customer-groups` |
| DELETE | `/admin/customer-groups/{code}` `[default]` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` |
| GET | `/admin/customer-groups/{code}` `[default]` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` |
| PUT | `/admin/customer-groups/{code}` `[default]` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` | `/admin/customer-groups/{code}` |

### Addresses (+ log entries)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | - | `/admin/address-log-entries/{id}` **NotExposed (404)** `[default]` | `/admin/address-log-entries/{id}` **NotExposed (404)** `[default]` | `/admin/address-log-entries/{id}` **NotExposed (404)** `[default]` | `/admin/address-log-entries/{id}` **NotExposed (404)** `[default]` |
| GET | `/admin/addresses/{id}` | `/admin/addresses/{id}` | `/admin/addresses/{id}` | `/admin/addresses/{id}` | `/admin/addresses/{id}` |
| PUT | `/admin/addresses/{id}` | `/admin/addresses/{id}` | `/admin/addresses/{id}` | `/admin/addresses/{id}` | `/admin/addresses/{id}` |
| GET | `/admin/addresses/{id}/log-entries` | `/admin/addresses/{id}/log-entries` `[resource-level]` | `/admin/addresses/{id}/log-entries` `[resource-level]` | `/admin/addresses/{id}/log-entries` `[resource-level]` | `/admin/addresses/{id}/log-entries` `[resource-level]` |

### Administrators (+ token / avatar / reset-password)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/administrators` `[default]` | `/admin/administrators` `[default]` | `/admin/administrators` `[default]` | `/admin/administrators` `[default]` | `/admin/administrators` `[default]` |
| POST | `/admin/administrators` `[default]` | `/admin/administrators` `[default]` | `/admin/administrators` `[default]` | `/admin/administrators` `[default]` | `/admin/administrators` `[default]` |
| POST | `/admin/administrators/reset-password` | `/admin/administrators/reset-password` | `/admin/administrators/reset-password` | `/admin/administrators/reset-password` | `/admin/administrators/reset-password` |
| PATCH | `/admin/administrators/reset-password/{token}` | `/admin/administrators/reset-password/{token}` | `/admin/administrators/reset-password/{token}` | `/admin/administrators/reset-password/{token}` | `/admin/administrators/reset-password/{token}` |
| POST | `/admin/administrators/token` | `/admin/administrators/token` | `/admin/administrators/token` | `/admin/administrators/token` | `/admin/administrators/token` |
| DELETE | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` |
| GET | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` |
| PUT | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` | `/admin/administrators/{id}` `[default]` |
| DELETE | - | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` |
| GET | - | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` |
| POST | - | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` | `/admin/administrators/{id}/avatar-image` `[resource-level]` |
| POST | `/admin/avatar-images` `[default]` | - | - | - | - |
| DELETE | `/admin/avatar-images/{id}` `[default]` | - | - | - | - |
| GET | `/admin/avatar-images/{id}` `[default]` | - | - | - | - |

### Promotions (+ coupons / archive / restore / rules / actions)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/promotion-actions/{id}` `[default]` | - | - | - | - |
| GET | `/admin/promotion-coupons` | - | - | - | - |
| POST | `/admin/promotion-coupons` | - | - | - | - |
| POST | `/admin/promotion-coupons/generate` | - | - | - | - |
| DELETE | `/admin/promotion-coupons/{code}` | - | - | - | - |
| GET | `/admin/promotion-coupons/{code}` | - | - | - | - |
| PUT | `/admin/promotion-coupons/{code}` | - | - | - | - |
| GET | `/admin/promotion-rules/{id}` `[default]` | - | - | - | - |
| GET | `/admin/promotions` `[default]` | `/admin/promotions` | `/admin/promotions` | `/admin/promotions` | `/admin/promotions` |
| POST | `/admin/promotions` `[default]` | `/admin/promotions` | `/admin/promotions` | `/admin/promotions` | `/admin/promotions` |
| DELETE | `/admin/promotions/{code}` `[default]` | `/admin/promotions/{code}` | `/admin/promotions/{code}` | `/admin/promotions/{code}` | `/admin/promotions/{code}` |
| GET | `/admin/promotions/{code}` `[default]` | `/admin/promotions/{code}` | `/admin/promotions/{code}` | `/admin/promotions/{code}` | `/admin/promotions/{code}` |
| PUT | `/admin/promotions/{code}` `[default]` | `/admin/promotions/{code}` | `/admin/promotions/{code}` | `/admin/promotions/{code}` | `/admin/promotions/{code}` |
| GET | - | `/admin/promotions/{code}/actions/{id}` **NotExposed (404)** | `/admin/promotions/{code}/actions/{id}` **NotExposed (404)** | `/admin/promotions/{code}/actions/{id}` **NotExposed (404)** | `/admin/promotions/{code}/actions/{id}` **NotExposed (404)** |
| PATCH | `/admin/promotions/{code}/archive` | `/admin/promotions/{code}/archive` | `/admin/promotions/{code}/archive` | `/admin/promotions/{code}/archive` | `/admin/promotions/{code}/archive` |
| GET | `/admin/promotions/{code}/coupons` `[subresource]` | `/admin/promotions/{promotionCode}/coupons` | `/admin/promotions/{promotionCode}/coupons` | `/admin/promotions/{promotionCode}/coupons` | `/admin/promotions/{promotionCode}/coupons` |
| POST | - | `/admin/promotions/{promotionCode}/coupons` | `/admin/promotions/{promotionCode}/coupons` | `/admin/promotions/{promotionCode}/coupons` | `/admin/promotions/{promotionCode}/coupons` |
| POST | - | `/admin/promotions/{promotionCode}/coupons/generate` | `/admin/promotions/{promotionCode}/coupons/generate` | `/admin/promotions/{promotionCode}/coupons/generate` | `/admin/promotions/{promotionCode}/coupons/generate` |
| DELETE | - | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` |
| GET | - | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` |
| PUT | - | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` | `/admin/promotions/{promotionCode}/coupons/{couponCode}` |
| PATCH | `/admin/promotions/{code}/restore` | `/admin/promotions/{code}/restore` | `/admin/promotions/{code}/restore` | `/admin/promotions/{code}/restore` | `/admin/promotions/{code}/restore` |
| GET | - | `/admin/promotions/{code}/rules/{id}` **NotExposed (404)** | `/admin/promotions/{code}/rules/{id}` **NotExposed (404)** | `/admin/promotions/{code}/rules/{id}` **NotExposed (404)** | `/admin/promotions/{code}/rules/{id}` **NotExposed (404)** |

### Catalog promotions (+ scopes / actions)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/catalog-promotion-actions/{id}` `[default]` | - | - | - | - |
| GET | `/admin/catalog-promotion-scopes/{id}` `[default]` | - | - | - | - |
| GET | `/admin/catalog-promotions` | `/admin/catalog-promotions` | `/admin/catalog-promotions` | `/admin/catalog-promotions` | `/admin/catalog-promotions` |
| POST | `/admin/catalog-promotions` | `/admin/catalog-promotions` | `/admin/catalog-promotions` | `/admin/catalog-promotions` | `/admin/catalog-promotions` |
| DELETE | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` |
| GET | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` |
| PUT | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` | `/admin/catalog-promotions/{code}` |
| GET | - | `/admin/catalog-promotions/{code}/actions/{id}` **NotExposed (404)** | `/admin/catalog-promotions/{code}/actions/{id}` **NotExposed (404)** | `/admin/catalog-promotions/{code}/actions/{id}` **NotExposed (404)** | `/admin/catalog-promotions/{code}/actions/{id}` **NotExposed (404)** |
| GET | - | `/admin/catalog-promotions/{code}/scopes/{id}` **NotExposed (404)** | `/admin/catalog-promotions/{code}/scopes/{id}` **NotExposed (404)** | `/admin/catalog-promotions/{code}/scopes/{id}` **NotExposed (404)** | `/admin/catalog-promotions/{code}/scopes/{id}` **NotExposed (404)** |

### Channels (+ shop billing data / price history config)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/channel-price-history-configs/{id}` `[default]` | - | - | - | - |
| PUT | `/admin/channel-price-history-configs/{id}` `[default]` | - | - | - | - |
| GET | `/admin/channels` | `/admin/channels` | `/admin/channels` | `/admin/channels` | `/admin/channels` |
| POST | `/admin/channels` | `/admin/channels` | `/admin/channels` | `/admin/channels` | `/admin/channels` |
| DELETE | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` |
| GET | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` |
| PUT | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` | `/admin/channels/{code}` |
| GET | `/admin/channels/{code}/shop-billing-data` `[subresource]` | - | - | - | - |
| GET | `/admin/shop-billing-datas/{id}` `[default]` | - | - | - | - |

### Currencies

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/currencies` | `/admin/currencies` | `/admin/currencies` | `/admin/currencies` | `/admin/currencies` |
| POST | `/admin/currencies` | `/admin/currencies` | `/admin/currencies` | `/admin/currencies` | `/admin/currencies` |
| GET | `/admin/currencies/{code}` | `/admin/currencies/{code}` | `/admin/currencies/{code}` | `/admin/currencies/{code}` | `/admin/currencies/{code}` |

### Locales

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/locales` | `/admin/locales` | `/admin/locales` | `/admin/locales` | `/admin/locales` |
| POST | `/admin/locales` | `/admin/locales` | `/admin/locales` | `/admin/locales` | `/admin/locales` |
| DELETE | `/admin/locales/{code}` | `/admin/locales/{code}` | `/admin/locales/{code}` | `/admin/locales/{code}` | `/admin/locales/{code}` |
| GET | `/admin/locales/{code}` | `/admin/locales/{code}` | `/admin/locales/{code}` | `/admin/locales/{code}` | `/admin/locales/{code}` |

### Countries / provinces

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/countries` | `/admin/countries` | `/admin/countries` | `/admin/countries` | `/admin/countries` |
| POST | `/admin/countries` | `/admin/countries` | `/admin/countries` | `/admin/countries` | `/admin/countries` |
| GET | `/admin/countries/{code}` | `/admin/countries/{code}` | `/admin/countries/{code}` | `/admin/countries/{code}` | `/admin/countries/{code}` |
| PUT | `/admin/countries/{code}` | `/admin/countries/{code}` | `/admin/countries/{code}` | `/admin/countries/{code}` | `/admin/countries/{code}` |
| GET | `/admin/countries/{code}/provinces` `[subresource]` | `/admin/countries/{code}/provinces` `[resource-level]` | `/admin/countries/{code}/provinces` `[resource-level]` | `/admin/countries/{code}/provinces` `[resource-level]` | `/admin/countries/{code}/provinces` `[resource-level]` |
| GET | - | `/admin/countries/{countryCode}/provinces/{provinceCode}` | `/admin/countries/{countryCode}/provinces/{provinceCode}` | `/admin/countries/{countryCode}/provinces/{provinceCode}` | `/admin/countries/{countryCode}/provinces/{provinceCode}` |
| PUT | - | `/admin/countries/{countryCode}/provinces/{provinceCode}` | `/admin/countries/{countryCode}/provinces/{provinceCode}` | `/admin/countries/{countryCode}/provinces/{provinceCode}` | `/admin/countries/{countryCode}/provinces/{provinceCode}` |
| GET | `/admin/provinces/{code}` | - | - | - | - |
| PUT | `/admin/provinces/{code}` | - | - | - | - |

### Zones (+ members)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/zone-members/{id}` | - | - | - | - |
| GET | `/admin/zones` | `/admin/zones` | `/admin/zones` | `/admin/zones` | `/admin/zones` |
| POST | `/admin/zones` | `/admin/zones` | `/admin/zones` | `/admin/zones` | `/admin/zones` |
| DELETE | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` |
| GET | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` |
| PUT | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` | `/admin/zones/{code}` |
| GET | `/admin/zones/{code}/members` `[subresource, derived]` | - | - | - | - |

### Exchange rates

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/exchange-rates` | `/admin/exchange-rates` | `/admin/exchange-rates` | `/admin/exchange-rates` | `/admin/exchange-rates` |
| POST | `/admin/exchange-rates` | `/admin/exchange-rates` | `/admin/exchange-rates` | `/admin/exchange-rates` | `/admin/exchange-rates` |
| DELETE | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` |
| GET | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` |
| PUT | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` | `/admin/exchange-rates/{id}` |

### Payment methods (+ gateway config)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/gateway-configs/{id}` `[default]` | `/admin/gateway-configs/{id}` **NotExposed (404)** | `/admin/gateway-configs/{id}` **NotExposed (404)** | `/admin/gateway-configs/{id}` **NotExposed (404)** | `/admin/gateway-configs/{id}` **NotExposed (404)** |
| GET | `/admin/payment-methods` | `/admin/payment-methods` | `/admin/payment-methods` | `/admin/payment-methods` | `/admin/payment-methods` |
| POST | `/admin/payment-methods` | `/admin/payment-methods` | `/admin/payment-methods` | `/admin/payment-methods` | `/admin/payment-methods` |
| DELETE | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` |
| GET | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` |
| PUT | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` | `/admin/payment-methods/{code}` |
| GET | `/admin/payment-methods/{code}/gateway-config` `[subresource]` | - | - | - | - |

### Shipping methods (+ archive / restore / rules)

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/shipping-method-rules/{id}` `[default]` | `/admin/shipping-method-rules/{id}` **NotExposed (404)** `[default]` | `/admin/shipping-method-rules/{id}` **NotExposed (404)** `[default]` | `/admin/shipping-method-rules/{id}` **NotExposed (404)** `[default]` | `/admin/shipping-method-rules/{id}` **NotExposed (404)** `[default]` |
| GET | `/admin/shipping-methods` | `/admin/shipping-methods` | `/admin/shipping-methods` | `/admin/shipping-methods` | `/admin/shipping-methods` |
| POST | `/admin/shipping-methods` | `/admin/shipping-methods` | `/admin/shipping-methods` | `/admin/shipping-methods` | `/admin/shipping-methods` |
| DELETE | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` |
| GET | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` |
| PUT | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` | `/admin/shipping-methods/{code}` |
| PATCH | `/admin/shipping-methods/{code}/archive` | `/admin/shipping-methods/{code}/archive` | `/admin/shipping-methods/{code}/archive` | `/admin/shipping-methods/{code}/archive` | `/admin/shipping-methods/{code}/archive` |
| PATCH | `/admin/shipping-methods/{code}/restore` | `/admin/shipping-methods/{code}/restore` | `/admin/shipping-methods/{code}/restore` | `/admin/shipping-methods/{code}/restore` | `/admin/shipping-methods/{code}/restore` |

### Shipping categories

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/shipping-categories` | `/admin/shipping-categories` | `/admin/shipping-categories` | `/admin/shipping-categories` | `/admin/shipping-categories` |
| POST | `/admin/shipping-categories` | `/admin/shipping-categories` | `/admin/shipping-categories` | `/admin/shipping-categories` | `/admin/shipping-categories` |
| DELETE | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` |
| GET | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` |
| PUT | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` | `/admin/shipping-categories/{code}` |

### Tax categories

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/tax-categories` | `/admin/tax-categories` | `/admin/tax-categories` | `/admin/tax-categories` | `/admin/tax-categories` |
| POST | `/admin/tax-categories` | `/admin/tax-categories` | `/admin/tax-categories` | `/admin/tax-categories` | `/admin/tax-categories` |
| DELETE | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` |
| GET | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` |
| PUT | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` | `/admin/tax-categories/{code}` |

### Tax rates

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/tax-rates` | `/admin/tax-rates` | `/admin/tax-rates` | `/admin/tax-rates` | `/admin/tax-rates` |
| POST | `/admin/tax-rates` | `/admin/tax-rates` | `/admin/tax-rates` | `/admin/tax-rates` | `/admin/tax-rates` |
| DELETE | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` |
| GET | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` |
| PUT | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` | `/admin/tax-rates/{code}` |

### Translation resources

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/catalog-promotion-translations/{id}` | - | - | - | - |
| GET | - | `/admin/catalog-promotions/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/catalog-promotions/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/catalog-promotions/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/catalog-promotions/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | `/admin/payment-method-translations/{id}` | - | - | - | - |
| GET | - | `/admin/payment-methods/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/payment-methods/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/payment-methods/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/payment-methods/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | `/admin/product-association-type-translations/{id}` `[default]` | - | - | - | - |
| GET | - | `/admin/product-association-types/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-association-types/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-association-types/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-association-types/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | `/admin/product-attribute-translations/{id}` | - | - | - | - |
| GET | - | `/admin/product-attributes/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-attributes/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-attributes/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-attributes/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | `/admin/product-option-translations/{id}` `[default]` | - | - | - | - |
| GET | - | `/admin/product-option-values/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-option-values/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-option-values/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-option-values/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | - | `/admin/product-options/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-options/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-options/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-options/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | `/admin/product-translations/{id}` | - | - | - | - |
| GET | `/admin/product-variant-translations/{id}` | - | - | - | - |
| GET | - | `/admin/product-variants/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-variants/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-variants/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/product-variants/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | - | `/admin/products/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/products/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/products/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/products/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | `/admin/promotion-translations/{id}` | - | - | - | - |
| GET | - | `/admin/promotions/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/promotions/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/promotions/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/promotions/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | `/admin/shipping-method-translations/{id}` | - | - | - | - |
| GET | - | `/admin/shipping-methods/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/shipping-methods/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/shipping-methods/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/shipping-methods/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |
| GET | `/admin/taxon-translations/{id}` | - | - | - | - |
| GET | - | `/admin/taxon/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/taxon/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/taxon/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) | `/admin/taxon/{code}/translations/{localeCode}` **NotExposed (404)** (NotExposed) |

### Statistics

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/statistics` | `/admin/statistics` | `/admin/statistics` | `/admin/statistics` | `/admin/statistics` |

### Other

| Method | 1.14 | 2.0 | 2.1 | 2.2 | 2.3 |
|---|---|---|---|---|---|
| GET | `/admin/channel-pricing-log-entries` | `/admin/channel-pricing-log-entries` | `/admin/channel-pricing-log-entries` | `/admin/channel-pricing-log-entries` | `/admin/channel-pricing-log-entries` |
| GET | `/admin/channel-pricing-log-entries/{id}` | `/admin/channel-pricing-log-entries/{id}` | `/admin/channel-pricing-log-entries/{id}` | `/admin/channel-pricing-log-entries/{id}` | `/admin/channel-pricing-log-entries/{id}` |

## Differences between consecutive lines (computed)

### 1.14 -> 2.0

Tier (b), URI variables masked: removed 42, added 37.

- REMOVED DELETE `/admin/avatar-images/{id}` (admin_delete)
- REMOVED DELETE `/admin/product-images/{id}` (admin_delete)
- REMOVED DELETE `/admin/promotion-coupons/{code}` (admin_delete)
- REMOVED DELETE `/admin/taxon-images/{id}` (admin_delete)
- REMOVED GET `/admin/avatar-images/{id}` (admin_get)
- REMOVED GET `/admin/catalog-promotion-actions/{id}` (admin_get)
- REMOVED GET `/admin/catalog-promotion-scopes/{id}` (admin_get)
- REMOVED GET `/admin/catalog-promotion-translations/{id}` (admin_get)
- REMOVED GET `/admin/channel-price-history-configs/{id}` (admin_get)
- REMOVED GET `/admin/channel-pricings/{id}` (admin_get)
- REMOVED GET `/admin/channels/{code}/shop-billing-data` (shop_billing_data_get_subresource)
- REMOVED GET `/admin/payment-method-translations/{id}` (admin_get)
- REMOVED GET `/admin/payment-methods/{code}/gateway-config` (gateway_config_get_subresource)
- REMOVED GET `/admin/product-association-type-translations/{id}` (admin_get)
- REMOVED GET `/admin/product-attribute-translations/{id}` (admin_get)
- REMOVED GET `/admin/product-images` (admin_get)
- REMOVED GET `/admin/product-images/{id}` (admin_get)
- REMOVED GET `/admin/product-option-translations/{id}` (admin_get)
- REMOVED GET `/admin/product-option-values/{code}` (admin_get)
- REMOVED GET `/admin/product-translations/{id}` (admin_get)
- REMOVED GET `/admin/product-variant-translations/{id}` (admin_get)
- REMOVED GET `/admin/promotion-actions/{id}` (admin_get)
- REMOVED GET `/admin/promotion-coupons` (admin_get)
- REMOVED GET `/admin/promotion-coupons/{code}` (admin_get)
- REMOVED GET `/admin/promotion-rules/{id}` (admin_get)
- REMOVED GET `/admin/promotion-translations/{id}` (admin_get)
- REMOVED GET `/admin/provinces/{code}` (admin_get)
- REMOVED GET `/admin/shipping-method-translations/{id}` (admin_get)
- REMOVED GET `/admin/shop-billing-datas/{id}` (admin_get)
- REMOVED GET `/admin/taxon-images` (admin_get)
- REMOVED GET `/admin/taxon-images/{id}` (admin_get)
- REMOVED GET `/admin/taxon-translations/{id}` (admin_get)
- REMOVED GET `/admin/zone-members/{id}` (admin_get)
- REMOVED GET `/admin/zones/{code}/members` (members_get_subresource)
- REMOVED POST `/admin/avatar-images` (admin_post)
- REMOVED POST `/admin/promotion-coupons` (admin_post)
- REMOVED POST `/admin/promotion-coupons/generate` (admin_generate)
- REMOVED PUT `/admin/channel-price-history-configs/{id}` (admin_put)
- REMOVED PUT `/admin/product-images/{id}` (admin_put)
- REMOVED PUT `/admin/promotion-coupons/{code}` (admin_put)
- REMOVED PUT `/admin/provinces/{code}` (admin_put)
- REMOVED PUT `/admin/taxon-images/{id}` (admin_put)
- ADDED DELETE `/admin/administrators/{id}/avatar-image` (sylius_api_admin_admin_user_avatar_image_delete)
- ADDED DELETE `/admin/products/{code}/images/{id}` (sylius_api_admin_product_product_image_delete)
- ADDED DELETE `/admin/promotions/{promotionCode}/coupons/{couponCode}` (sylius_api_admin_promotion_promotion_coupon_delete)
- ADDED DELETE `/admin/taxons/{code}/images/{id}` (sylius_api_admin_taxon_taxon_image_delete)
- ADDED GET `/admin/address-log-entries/{id}` **NotExposed (404)** (sylius_api_admin_address_log_entry_get)
- ADDED GET `/admin/administrators/{id}/avatar-image` (sylius_api_admin_admin_user_avatar_image_get)
- ADDED GET `/admin/catalog-promotions/{code}/actions/{id}` **NotExposed (404)** (sylius_api_admin_catalog_promotion_catalog_promotion_action_get)
- ADDED GET `/admin/catalog-promotions/{code}/scopes/{id}` **NotExposed (404)** (sylius_api_admin_catalog_promotion_catalog_promotion_scope_get)
- ADDED GET `/admin/catalog-promotions/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_catalog_promotion_catalog_promotion_translation_get)
- ADDED GET `/admin/countries/{countryCode}/provinces/{provinceCode}` (sylius_api_admin_country_province_get)
- ADDED GET `/admin/payment-methods/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_payment_method_payment_method_translation_get)
- ADDED GET `/admin/payment-requests/{hash}` (sylius_api_admin_payment_request_get)
- ADDED GET `/admin/payments/{paymentId}/payment-requests` (sylius_api_admin_payment_payment_request_get_collection)
- ADDED GET `/admin/product-association-types/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_product_association_type_product_association_type_translation_get_collection)
- ADDED GET `/admin/product-attributes/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_product_attribute_product_attribute_translation_get_collection)
- ADDED GET `/admin/product-option-values/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_product_option_value_product_option_value_translation_get_collection)
- ADDED GET `/admin/product-options/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_product_option_product_option_translation_get_collection)
- ADDED GET `/admin/product-options/{optionCode}/values/{code}` (sylius_api_admin_product_option_product_option_value_get)
- ADDED GET `/admin/product-variants/{code}/channel-pricings/{channelCode}` **NotExposed (404)** (sylius_api_admin_product_variant_channel_pricing_get)
- ADDED GET `/admin/product-variants/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_product_variant_product_variant_translation_get_collection)
- ADDED GET `/admin/products/{code}/images/{id}` (sylius_api_admin_product_product_image_get)
- ADDED GET `/admin/products/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_product_product_translation_get_collection)
- ADDED GET `/admin/promotions/{code}/actions/{id}` **NotExposed (404)** (sylius_api_admin_promotion_promotion_action_get)
- ADDED GET `/admin/promotions/{promotionCode}/coupons/{couponCode}` (sylius_api_admin_promotion_promotion_coupon_get)
- ADDED GET `/admin/promotions/{code}/rules/{id}` **NotExposed (404)** (sylius_api_admin_promotion_promotion_rule_get)
- ADDED GET `/admin/promotions/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_promotion_promotion_translation_get_collection)
- ADDED GET `/admin/shipping-methods/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_shipping_method_shipping_method_translation_get_collection)
- ADDED GET `/admin/taxon/{code}/translations/{localeCode}` **NotExposed (404)** (sylius_api_admin_taxon_taxon_translation_get_collection)
- ADDED GET `/admin/taxons/{code}/images/{id}` (sylius_api_admin_taxon_taxon_image_get)
- ADDED PATCH `/admin/payments/{id}/refund` (sylius_api_admin_payment_patch_refund)
- ADDED POST `/admin/administrators/{id}/avatar-image` (sylius_api_admin_admin_user_avatar_image_post)
- ADDED POST `/admin/promotions/{promotionCode}/coupons` (sylius_api_admin_promotion_promotion_coupon_post)
- ADDED POST `/admin/promotions/{promotionCode}/coupons/generate` (sylius_api_admin_promotion_promotion_coupon_post_generate)
- ADDED PUT `/admin/countries/{countryCode}/provinces/{provinceCode}` (sylius_api_admin_country_province_put)
- ADDED PUT `/admin/products/{code}/images/{id}` (sylius_api_admin_product_product_image_put)
- ADDED PUT `/admin/promotions/{promotionCode}/coupons/{couponCode}` (sylius_api_admin_promotion_promotion_coupon_put)
- ADDED PUT `/admin/taxons/{code}/images/{id}` (sylius_api_admin_taxon_taxon_image_put)

Tier (a), same route but URI variable renamed: 1.

- GET `/admin/promotions/{code}/coupons` -> `/admin/promotions/{promotionCode}/coupons`

Same route, normalization/denormalization groups or input class changed: 139.

(1.14 -> 2.0: every common route changes groups because the legacy unprefixed `admin:*` names are dropped and the operation names change from `admin_*` to `sylius_api_admin_*`; not listed individually.)

### 2.0 -> 2.1

Tier (b), URI variables masked: removed 0, added 0.


Tier (a), same route but URI variable renamed: 0.


Same route, normalization/denormalization groups or input class changed: 0.


### 2.1 -> 2.2

Tier (b), URI variables masked: removed 0, added 0.


Tier (a), same route but URI variable renamed: 0.


Same route, normalization/denormalization groups or input class changed: 0.


### 2.2 -> 2.3

Tier (b), URI variables masked: removed 0, added 0.


Tier (a), same route but URI variable renamed: 0.


Same route, normalization/denormalization groups or input class changed: 0.


