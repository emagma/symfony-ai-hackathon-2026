# Sylius MCP plugin (Sylius 2.x) - developer guide

- **Composer name**: `emagma/sylius-mcp-plugin`
- **Namespace**: `Emagma\SyliusMcpPlugin`
- **Config alias**: `emagma_sylius_mcp` (for other bundles; a shop configures nothing there)
- **Depends on**: `sylius/sylius` ^2.0, API Platform 4 or 5 (`api-platform/doctrine-orm`, `metadata`, `state`), Doctrine ORM, Symfony 6.4, 7.4 or 8.1, `composer-runtime-api` ^2.2.
  PHP 8.3.
- **Depended on by**: the shop halves of MCP extensions, through its extension points.
  The MCP server (`mcp-sylius-bundle`) only talks to it over HTTP.

## How it is built

| Class | Role |
|---|---|
| `ApiPlatform\Lean\LeanRequestDetector` | Is this request lean? Which locale did it name? |
| `EventListener\LeanVaryListener` | `Vary: Prefer` and `Sylius-Mcp-Plugin: 1` on API responses |
| `ApiPlatform\Serializer\LeanContextBuilder` | Swaps the normalization groups for the lean group |
| `ApiPlatform\Serializer\LeanTranslationTrimNormalizer` | Keeps one locale in `translations` |
| `ApiPlatform\Metadata\LeanFilterResourceMetadataCollectionFactory` | Adds the lean filters to Sylius's `GetCollection` operations |
| `ApiPlatform\Filter\LeanGatedFilter` | Base class of every filter: the gate, the error policy, the coercions |
| `ApiPlatform\Filter\*Filter` | The ten filters (products, orders, customers, promotions, taxons) |
| `ApiPlatform\Extension\TaxonTreeOrderExtension` | Lean taxon lists in tree order |
| `ApiResource\Capabilities`, `ApiPlatform\State\CapabilitiesProvider` | `GET /admin/sylius-mcp/capabilities` |
| `Command\CreateServiceAccountCommand` | `sylius-mcp:service-account` |
| `Compat\TranslatableDenormalizer` | Sylius's denormalizer as fixed in 2.1.13 |
| `DependencyInjection\Compiler\FixTranslatableDenormalizerPass` | Puts it in place on the affected versions |
| `DependencyInjection\Compiler\ResolveLeanMapsPass` | Resolves the `%parameter%` keys of the lean maps |
| `DependencyInjection\Configuration`, `LeanMaps` | The extension points and their merge rules |

`config/services.php` declares every service by hand: explicit arguments and tags, private, ids prefixed by the alias (`emagma_sylius_mcp.lean.request_detector`, `emagma_sylius_mcp.api.lean_context_builder`, `emagma_sylius_mcp.api.capabilities_provider`, `emagma_sylius_mcp.command.service_account`...), each with its class as an alias.
`config/serializer/` holds one XML mapping per Sylius model, adding the `mcp:<resource>:lean` group to a few attributes.

### A lean request, step by step

1. The caller sends `Prefer: respond-for=lean[; locale=xx]`.
   `LeanRequestDetector` is the one place that parses it (RFC 7240: several tokens, quoted or not, across several headers).
   The locale must look like a Sylius locale code (`xx` or `xx_XX`), otherwise it is ignored.
2. `LeanFilterResourceMetadataCollectionFactory` decorates `api_platform.metadata.resource.metadata_collection_factory` (priority 700, below Sylius's own factories at 799) and has already appended the lean filter ids to the `GetCollection` whose `uriTemplate` the map names.
   It is idempotent: an id already there is not added twice.
3. Each filter extends `LeanGatedFilter`, whose `filterProperty()` is final and returns at once outside lean mode.
   In lean mode it calls `filterLeanProperty()`.
4. `TaxonTreeOrderExtension` (collection query extension, priority 0, above API Platform's `OrderExtension` at -32) orders a lean taxon list by `root`, then `left`.
5. `LeanContextBuilder` decorates `api_platform.serializer.context_builder`.
   On normalization only, in lean mode, for a resource class matched with `is_a()` in the group map (first match wins), it replaces `groups` with the lean group.
   A class with no entry keeps the full payload.
6. `LeanTranslationTrimNormalizer` (serializer normalizer, priority 128) runs on any `TranslatableInterface` in lean mode.
   When the caller named a locale, it keeps that locale's translation (or the first one if that locale is missing) and strips its `@id` and `@type`.
7. `LeanVaryListener` (`kernel.response`, main request, `GET`/`HEAD` under `/api/`) adds `Sylius-Mcp-Plugin: 1` and `Vary: Prefer`.

### The filters

`LeanGatedFilter` extends API Platform's `AbstractFilter`.
What it fixes for every subclass:

- **The gate**: nothing happens outside lean mode, so the admin UI and third-party consumers never see a change.
- **The error policy**: malformed input is skipped and logged at `info` (`skip()`), never a 400.
- **The coercions**: `nonEmptyString()`, `stringList()`, `toBool()` (`1`, `true`, `yes`, `on` and their opposites), `toInt()`, `toDate()` (refuses any date PHP parsed with a warning: `"a while ago"` would otherwise mean now), `containsPattern()` (lower-cased, `%` and `_` escaped).
- **`LEAN_NOTE`**: the prefix of every parameter description in OpenAPI.

Two rules the filters follow:

- **Correlated subqueries, not `GROUP BY`**: API Platform paginates with a `COUNT` over the same query builder.
  A join plus `GROUP BY` / `HAVING` on the root would count groups, and `totalItems` / `hasMore` would lie.
  `OrderItemsFilter`, `CustomerOrderAggregateFilter` and the taxon `name` filter use subqueries for that reason.
  `ProductChannelPricingFilter` joins variants and pricings in one `WITH` clause (channel and bounds checked on the same pricing row) and adds `DISTINCT`.
- **No sum across currencies**: a monetary bound on products or on customer aggregates needs a channel, or it is a logged no-op.

| Service id | Class | Collection |
|---|---|---|
| `emagma_sylius_mcp.api.filter.product_attribute_value` | `ProductAttributeValueFilter` | `/admin/products` |
| `emagma_sylius_mcp.api.filter.product_channel_pricing` | `ProductChannelPricingFilter` | `/admin/products` |
| `emagma_sylius_mcp.api.filter.order_criteria` | `OrderCriteriaFilter` | `/admin/orders` |
| `emagma_sylius_mcp.api.filter.order_totals` | `OrderTotalsFilter` | `/admin/orders` |
| `emagma_sylius_mcp.api.filter.order_sort` | `OrderSortFilter` | `/admin/orders` |
| `emagma_sylius_mcp.api.filter.order_items` | `OrderItemsFilter` | `/admin/orders` |
| `emagma_sylius_mcp.api.filter.customer_criteria` | `CustomerCriteriaFilter` | `/admin/customers` |
| `emagma_sylius_mcp.api.filter.customer_order_aggregate` | `CustomerOrderAggregateFilter` | `/admin/customers` |
| `emagma_sylius_mcp.api.filter.promotion_criteria` | `PromotionCriteriaFilter` | `/admin/promotions` |
| `emagma_sylius_mcp.api.filter.taxon_criteria` | `TaxonCriteriaFilter` | `/admin/taxons` |

The query keys of each are listed in the [user guide](user-guide.md) and in each class's docblock.

### The compiler passes

- **`ResolveLeanMapsPass`**: the keys of both lean maps may be `%parameter%` (`%sylius.model.product.class%`).
  The pass resolves them, then merges the entries that turn out to name the same class, so that a parameter and the literal class it names add up instead of one replacing the other.
  A key that does not resolve to a non-empty string fails the compilation.
- **`FixTranslatableDenormalizerPass`**: reads the installed `sylius/sylius` version (`Composer\InstalledVersions`).
  On a version below 2.1.13, or from 2.2.0 to 2.2.3, it sets the class of `sylius_api.denormalizer.translatable` to `Compat\TranslatableDenormalizer` (the class of Sylius 2.1.13, MIT).
  A branch version (`dev-…`) is never patched.
  The bug: the old denormalizer also claimed the IRI string of a translatable relation in a POST body and crashed on it ("Cannot access offset of type string on string").

### The capabilities endpoint

`ApiResource\Capabilities` is an API Platform resource of the plugin (its directory is added to `api_platform.mapping.paths`; it holds nothing else, since API Platform scans every class there): one `Get` at `/admin/sylius-mcp/capabilities`, operation name `emagma_sylius_mcp_api_admin_capabilities_get`, no Doctrine, no identifier.
`CapabilitiesProvider` publishes each declared extension with `InstalledVersions::getPrettyVersion()` of its package, leading `v` removed.
A declared extension whose package is not installed is left out and logged as a warning: the MCP server would otherwise enable MCP Tools whose routes do not exist.
The normalization context keeps an empty `extensions` as `{}`.

### The service account command

A classic `configure()` / `execute()` command, not an invokable one with `#[Option]`: that needs Symfony 7.3, and a Sylius 2.0 shop may run 6.4.
It hashes the password itself (Sylius's password listener only fires on a Doctrine change set, and a plain password alone is not one).

## Extension points

The shop half of an MCP extension (here, one for the official CMS plugin) does not edit this plugin: it prepends its configuration from its own `prepend()`.

```php
$container->prependExtensionConfig('emagma_sylius_mcp', [
    'lean' => [
        // resource class (or %parameter%) => lean group
        'groups' => ['%sylius_cms.model.page.class%' => 'mcp:cms_page:lean'],
        // resource class => (GetCollection uriTemplate => filter service ids)
        'filters' => ['%sylius_cms.model.page.class%' => [
            '/admin/cms/pages' => ['emagma_sylius_mcp_cms.api.filter.page_criteria'],
        ]],
    ],
    // extension name => Composer package whose installed version is published
    'extensions' => ['sylius_cms' => 'sylius/cms-plugin'],
]);
```

### `lean.groups`

- **Merge**: the plugin's entries (`Configuration::CORE_LEAN_GROUPS`) first, whatever the bundle order, then the contributions.
- **Matching**: `is_a()`, in that order, first match wins: a shop's overriding model inherits the group of its Sylius parent.
- **Same class twice**: the later group wins, at the position of the first.
- **The group itself**: declare it in your own serializer mapping, on the Sylius (or plugin) class, and add the mapping directory to `framework.serializer.mapping.paths` from your `prepend()`.
  Symfony merges mappings of the same class additively.

### `lean.filters`

- **Merge**: the plugin's entries (`Configuration::CORE_LEAN_FILTERS`) first.
  Per class and per `uriTemplate`, the filter ids add up, without duplicates.
- **Matching**: exact class (API Platform reports the configured model class), then the `uriTemplate` of a `GetCollection` operation of that class.
- **The filter**: a service tagged `api_platform.filter` that you declare yourself, preferably a subclass of `LeanGatedFilter` so that it stays invisible outside lean mode.

### `extensions`

- **Keys**: `snake_case` names (`^[a-z][a-z0-9_]*$`), the name the MCP server activates.
- **Values**: a Composer package, `vendor/name`.
- **Merge**: Symfony's usual merge of a prototyped array.

After a change to either map, clear API Platform's metadata pools (see Traps): until then the new filter is silently ignored.

### Adding a lean group to a Sylius resource of the plugin

Add the `<group>mcp:<resource>:lean</group>` lines to the XML of `config/serializer/` (on the Sylius model class, not the shop's), and an entry to `Configuration::CORE_LEAN_GROUPS`.

### Adding a filter to the plugin

Extend `LeanGatedFilter`, implement `filterLeanProperty()` (ignore silently the keys you do not own: every filter of an operation sees every query key) and `getDescription()` (prefix with `LEAN_NOTE`).
Declare the service in `config/services.php` with the tag `api_platform.filter`, and add its id to `Configuration::CORE_LEAN_FILTERS` under its collection.

## Configuration reference

- **`lean.groups`**
  - **Default**: `{}` (the core entries are merged by the extension, not defaults of the tree)
  - **What the extension does**: Argument 2 of `emagma_sylius_mcp.api.lean_context_builder`
- **`lean.filters`**
  - **Default**: `{}` (same)
  - **What the extension does**: Argument 1 of `emagma_sylius_mcp.api.filter.metadata_factory`
- **`extensions`**
  - **Default**: `{}`
  - **What the extension does**: Argument 0 of `emagma_sylius_mcp.api.capabilities_provider`

- The core entries are not defaults of the tree on purpose: a prototyped node's default is replaced, not merged, as soon as anyone configures it.
- The extension prepends `framework.serializer.mapping.paths` (the lean groups) and `api_platform.mapping.paths` (the capabilities resource).
- The command reads `%env(default::MCP_SERVICE_ACCOUNT_EMAIL)%` and `%env(default::MCP_SERVICE_ACCOUNT_PASSWORD)%`, and `%locale%` for a new account's locale.

## Data

No table of its own.
The service account is a Sylius admin user.

## Tests and quality

The package runs on its own: `composer install`, then `vendor/bin/phpunit`, `vendor/bin/ecs check` and `vendor/bin/phpstan analyse`.
Inside a shop that installs it, run the shop's tools with the package's configuration files: `tests/bootstrap.php` uses the autoloader of the PHPUnit that runs and registers the test namespace.

| Test | What it covers |
|---|---|
| `tests/Unit/ApiPlatform/Filter/*Test.php` | The DQL each filter produces (recorded query builder calls, `OrderFilterTestCase`) |
| `tests/Unit/ApiPlatform/Lean/*Test.php`, `tests/Unit/EventListener/*Test.php` | Parsing `Prefer`, the response headers |
| `tests/Unit/ApiPlatform/Serializer/*Test.php` | The group swap, the translation trimming |
| `tests/Unit/ApiPlatform/Metadata/*Test.php` | Filters appended to the right operation |
| `tests/Unit/ApiPlatform/Extension/*Test.php` | The taxon tree order |
| `tests/Unit/ApiPlatform/State/CapabilitiesProviderTest.php` | Published versions, missing packages |
| `tests/Unit/DependencyInjection/ConfigurationTest.php` | The tree and its validation |
| `tests/Unit/DependencyInjection/ExtensionPointsTest.php` | A contributing bundle (`Fixture/ContributingExtension`), down to the service arguments |
| `tests/Unit/DependencyInjection/ServicesDefinitionTest.php` | `config/services.php` and its argument shapes |
| `tests/Unit/DependencyInjection/PrependedConfigTest.php` | The prepended paths, the capabilities provider id, the class aliases |
| `tests/Unit/DependencyInjection/Compiler/FixTranslatableDenormalizerPassTest.php` | Which versions get the fix |

```bash
# alone
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse

# inside a shop, from the shop's directory
vendor/bin/ecs check --config vendor/emagma/sylius-mcp-plugin/ecs.php
vendor/bin/phpunit -c vendor/emagma/sylius-mcp-plugin/phpunit.xml.dist
```

PHPStan analyses against PHP 8.3 (`phpVersion: 80300`), the package's floor.

## Traps

- **API Platform's metadata pools survive `cache:clear` and a container restart.** A new filter, a new entry in a lean map or a change in the metadata factory stays invisible until:

  ```bash
  bin/console cache:pool:clear api_platform.cache.metadata.resource \
      api_platform.cache.metadata.resource_collection api_platform.cache.metadata.operation \
      api_platform.cache.metadata.property cache.system
  ```

  The failure is silent: the collection answers 200 and ignores every parameter.
- **A lean filter must never act outside lean mode.** That is why `filterProperty()` is final in `LeanGatedFilter`, and why order sorting is a `sort` filter and not new properties on Sylius's `order` filter (an API Platform `OrderFilter` cannot be gated).
- **A join on a to-many relation can multiply rows**: prefer a correlated subquery, or add `DISTINCT` as `ProductChannelPricingFilter` does.
  The paginator counts the same query.
- **`LeanTranslationTrimNormalizer` is not cacheable by type** (`getSupportedTypes()` returns `false`): its decision depends on the request header.
- **Declare a lean group on the Sylius model, not on a shop's class**: the shop's overriding class inherits the parent's serializer mapping, and `LeanContextBuilder` matches it with `is_a()`.
- **Bundle order does not matter for the maps**: the core entries are merged first by the extension.
