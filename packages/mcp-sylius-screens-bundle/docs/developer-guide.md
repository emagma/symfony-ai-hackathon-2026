# MCP Sylius Screens Bundle: developer guide

- **Composer name**: `emagma/mcp-sylius-screens-bundle`
- **Namespace**: `Emagma\McpSyliusScreensBundle`
- **Bundle**: `EmagmaMcpSyliusScreensBundle`, config alias `emagma_mcp_sylius_screens` (no key)
- **Twig namespace**: `@EmagmaMcpSyliusScreens` (`templates/`)
- **Depends on**: `emagma/mcp-core-bundle`, `emagma/mcp-screens-bundle`, `emagma/mcp-sylius-bundle`

A small bundle: two screens, one helper, no MCP Tool of its own.
The app, its three tools and the contract of a screen are in `emagma/mcp-screens-bundle`: read its developer guide first.

## How it is built

| Class or file | Role |
|---|---|
| `src/Screen/ProductVariantsScreen.php` | The screen `product_variants` |
| `src/Screen/AddressScreen.php` | The screen `address` |
| `src/Money.php` | Typed amounts to and from minor units |
| `templates/product_variants.html.twig` | Its fragment |
| `templates/address.html.twig` | Its fragment |
| `config/services.php` | The two screens, tagged `emagma_mcp_screens.screen` |

Both screens follow the same shape:

1. A private `read($params)` builds the data, and `load()` returns it.
2. `submit()` calls `read()` again and compares what was typed with the current values.
3. It writes only the differences, by invoking the service of a tool of the Sylius bundle with `confirm: false` and the `RequestContext`.
4. The tool then asks the human and journals: a save from the screen is that tool call.

### Twig namespace

TwigBundle derives the namespace from the bundle class: `EmagmaMcpSyliusScreensBundle` gives `@EmagmaMcpSyliusScreens`.
A screen returns `@EmagmaMcpSyliusScreens/<name>.html.twig` from `template()`.
`tests/Integration` renders both templates on a minimal kernel: a wrong namespace fails there.

### ProductVariantsScreen

- **Injected**: `SearchProductVariants`, `GetProductVariants`, `GetChannels`, `UpsertProductVariants`.
- **Permissions**: read `sylius:product_variant:read` and `sylius:channel:read`; write `sylius:product_variant:write`.

`read()`:

1. `SearchProductVariants(product: $productCode, itemsPerPage: 50)` gives the codes.
2. `GetProductVariants($codes)['found']` gives `pricing`, `tracked`, `onHand`, `onHold`.
3. The channels are the keys of every variant's `pricing`.
   The asked `channelCode` is kept if it is one of them, the first otherwise.
4. `GetChannels([$channelCode])` gives `baseCurrencyCode`.
5. It returns `productCode`, `channelCode`, `channelCodes`, `currency`, `variants` and `more`.

`submit()`:

- Fields are `price_<index>` and `stock_<index>`, the index being the variant's position in `variants`.
- An empty field is skipped; a stock is read only for a tracked variant.
- Changed variants become items `{code, pricing: {<channel>: <minor>}, onHand}` of one `($this->upsert)($items, 'update', false, $context)`.
- When the tool answers `ok` false, its `reason` becomes a message.
- A per-item failure gives "N variant(s) saved, not saved: CODE (reason)."

### AddressScreen

- **Injected**: `AdminApiClientInterface` and `UpdateAddress`.
- **Permissions**: read `sylius:address:read`, `sylius:order:read`, `sylius:customer:read` and `core:pii:reveal`; write `sylius:address:write`.

`find()` resolves the params in this order: `orderToken` (with `kind`), `customerId`, `addressId`.
It returns the subject line, the address and, for the shipping address of a shipped order, the reason it is locked.
A `ClientException` becomes a `ScreenException` ("Not found in the shop." on 404).

`embedded()` handles the three shapes of an address in an order or a customer: whole, as an IRI string, or as an object without `street`.
In the last two cases the address is read by its id.

`read()` returns `subject`, `addressId`, `locked` and `fields` (per field: `label`, `value`, `required`).

`submit()`:

- It refuses a locked address or one without identifier.
- It validates the required fields and `countryCode` (`/^[A-Z]{2}$/`).
- It calls `($this->update)($data['addressId'], ...$changes, confirm: false, context: $context)`.
- The shop's violations (`errors: [{property, message}]`) come back as field errors.

### Money

A static helper.
It knows the zero-decimal and three-decimal currencies, and treats any other as two-decimal.

```php
Money::decimals('JPY');        // 0
Money::format(9905, 'USD');    // "99.05"
Money::parse('12,5', 'USD');   // 1250
Money::parse('12.345', 'USD'); // null: not an amount in a two-decimal currency
Money::parse('-3', 'USD');     // null
```

## Extension points

None of its own.
To add a Sylius screen, implement `Emagma\McpScreensBundle\ScreenInterface` (see "Add a screen" in the developer guide of the screens bundle) and declare it in `config/services.php`:

```php
$services->set('emagma_mcp_sylius_screens.screen.my_screen', MyScreen::class)
    ->tag('emagma_mcp_screens.screen');
```

Follow the two existing screens:

- inject the tools of the Sylius bundle for the write,
- pass `confirm: false` and the `$context`,
- translate the tool's `reason` into a `ScreenResult`.

## Configuration reference

No configuration key, nothing prepended.
`loadExtension()` only imports `config/services.php`.
The permission domains of the screens are written in their `Permission` objects (`'sylius'`, `'core'`).

## Data

None of its own.
The writes are those of `upsert_product_variants` and `update_address`, journaled by those tools.

## Tests and quality

- **`tests/Unit`**: both screens extend `Emagma\McpSyliusBundle\Testing\McpToolTestCase` and run against a mocked `AdminApiClientInterface` with the real tools.
  They cover loading, what the screen says when it cannot show, the lock of a shipped order, validation before any write, unchanged values not written, and a change stopped by the tool's confirmation when there is no request context.
  `MoneyTest` covers parsing and formatting.
- **`tests/Integration`**: a minimal kernel (the core, the screens bundle, the Sylius bundle with a line of its own, Twig) checks that both screens are on the registry and that both templates render in display and form modes.
- **Alone**: `composer install`, then `vendor/bin/phpunit`, `vendor/bin/ecs check` and `vendor/bin/phpstan analyse`.
- **From the repository**: `bin/ci/package.sh mcp-sylius-screens-bundle highest` (or `lowest`).

## Traps

- **The screens call the tools' services directly**, not through the MCP registry.
  The grants of those tools are not checked on that path.
  `readPermissions()` and `writePermissions()` are the only guard, and must list what the injected tools read and write.
- **`AddressScreen` shows a person's address in clear, whatever the client.**
  Keep `core:pii:reveal` if the screen changes.
- **Field indexes** (`price_0`, `stock_0`) rely on `read()` returning the variants in the same order at display and at submit.
- **Without a `RequestContext`** the tools preview and write nothing.
  The screen then answers "This client cannot ask you to confirm a write: nothing was saved."
- **Tests never name a line's adapter**: `McpToolTestCase` provides `adapter()`.
- **`Money` knows only its two lists**: a currency with other decimals than 0, 2 or 3 is treated as two-decimal.
