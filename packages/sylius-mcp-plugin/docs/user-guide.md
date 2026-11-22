# Sylius MCP plugin (Sylius 2.x) - user guide

`emagma/sylius-mcp-plugin` is what a Sylius 2.x shop installs so that the MCP server can drive it through the Admin API.
It holds nothing MCP: no MCP Tool, no authorization.
It makes the Admin API answer short payloads and the searches the MCP Tools need, and it creates the one admin account the server calls the API with.
This guide is for the person who installs it in a shop.

## What you can do

### Short payloads for the MCP server

- **Lean mode**: a request that carries `Prefer: respond-for=lean` (RFC 7240) gets a short representation of the resource (`code`, name and the few fields the MCP Tools read) instead of the full admin payload.
- **One locale**: `Prefer: respond-for=lean; locale=fr_FR` keeps only that locale in `translations`.
  When the resource has no translation in that locale, the first one is kept, so a name never comes back empty.
  Without `locale`, every translation stays.
- **Resources**: products, variants, taxons, taxon images, product attributes and options, channels, countries, currencies, locales, zones, exchange rates, payment and shipping methods, shipping and tax categories, tax rates, orders, payments, shipments, customers, promotions, catalog promotions.
- **Taxon lists in tree order**: in lean mode, `/admin/taxons` comes back root by root, parents before children, siblings in the admin's order.
- **Nothing changes for anyone else**: the admin UI and any other API consumer send no such header and see the same API as before.
  API responses carry `Vary: Prefer`, so a cache in front of the shop keeps the two representations apart.

### Searches the Sylius API does not offer

These query parameters only act in lean mode.
Outside it, API Platform ignores them as it ignores any unknown parameter.

| Collection | Query parameters |
|---|---|
| `/admin/products` | `attribute[<code>]`, `attribute_present[]`, `attribute_range[<code>][gte\|lte]`, `price[channel]` + `price[gte\|lte]` |
| `/admin/orders` | `state[]`, `paymentState[]`, `shippingState[]`, `number`, `customerEmail` |
| `/admin/orders` | `itemsTotal[gte\|lte]`, `adjustmentsTotal[gte\|lte]` |
| `/admin/orders` | `itemsCount[gte\|lte]`, `unitsCount[gte\|lte]`, `containsProduct[]`, `containsVariant[]` |
| `/admin/orders` | `sort[by]` (`checkoutCompletedAt`, `total`, `number`), `sort[direction]` |
| `/admin/customers` | `createdAt[after\|before]`, `subscribedToNewsletter`, `hasAccount`, `groupCode` |
| `/admin/customers` | `ordersTotal[gte\|lte]`, `ordersCount[gte\|lte]`, `lastOrder[after\|before]`, `orderAggregate[channel]`, `orderAggregate[paymentStates][]` |
| `/admin/promotions` | `name`, `code`, `channel`, `couponBased`, `exclusive`, `activeAt` |
| `/admin/taxons` | `name`, `code`, `parentCode`, `rootOnly`, `enabled`, `subtreeOf`, `depth` |

- **Amounts are minor units** (`5000` is 50.00 in a two-decimal currency).
- **A price range needs a channel**: `price[gte|lte]` without `price[channel]` does nothing, and `ordersTotal[gte|lte]` without `orderAggregate[channel]` does nothing either.
  Prices and totals of different currencies cannot be compared.
- **Customer aggregates count paid orders by default**, never carts; `orderAggregate[paymentStates][]` changes the payment states counted.
- **`sort[direction]` defaults to `desc`.** The key is `sort`, not `order`: Sylius's own `order` filter stays untouched.
- **A malformed value is ignored**, never a 400: a date PHP cannot read exactly, an unknown attribute code, a range on a text attribute.
  The ignored key is logged at `info` level.

Example:

```bash
curl -H 'Prefer: respond-for=lean; locale=en_US' -H "Authorization: Bearer $TOKEN" \
    'https://shop.example.com/api/v2/admin/products?price[channel]=FASHION_WEB&price[gte]=1000&price[lte]=2000'
```

### The capabilities endpoint

`GET /api/v2/admin/sylius-mcp/capabilities` tells the MCP server what the shop runs:

```json
{"extensions": {"sylius_cms": "1.1.6"}, "sylius": "2.3.2"}
```

- **`sylius`**: the installed version of `sylius/sylius`.
  The MCP server checks its configured Sylius line (`MCP_SYLIUS_VERSION`) against it.
- **`extensions`**: the shop halves of MCP extensions installed in the shop, each with the version of the package it covers (`sylius_cms` in the example above).
  `{}` when there is none.
- **Versions** are Composer's, without the tag's leading `v` (`v1.1.6` gives `1.1.6`); a branch (`dev-main`) stays as it is.
- Ask for `Accept: application/json`: in JSON-LD the same fields come with the JSON-LD envelope.

### The service account

`bin/console sylius-mcp:service-account` creates the one Sylius admin the MCP server calls the Admin API with.
See "The service account" below.

### A Sylius bug fixed on the versions that have it

Before Sylius 2.1.13, and from 2.2.0 to 2.2.3, Sylius's `TranslatableDenormalizer` crashes (500) on the IRI of a translatable relation in a POST body: a variant (`product`), a product (`mainTaxon`, `options`) or a taxon (`parent`) cannot be created through the API.
On those versions only, the plugin swaps in the fixed class (Sylius's own, as of 2.1.13).
Other versions keep Sylius's class.

## Without the plugin

The MCP server still works, in a degraded way:

- the API answers its full payloads: heavier for the model, slower;
- the parameters above are **silently ignored** by API Platform: a search with a price range or an order state returns everything instead of the matching items.

The server notices: the plugin sets `Sylius-Mcp-Plugin: 1` on every API response to a `GET` or `HEAD`, and the server logs a warning, once per process, when the header is missing.
There is no version negotiation: the server and the plugin are released together.

## Setting it up

### Requirements

- PHP 8.3 or later
- `sylius/sylius` 2.x
- API Platform 4 or 5
- Symfony 6.4, 7.4 or 8.1
- Doctrine ORM 2.18 or later, or 3

### Install

```bash
composer require emagma/sylius-mcp-plugin
```

Then:

1. **Register the bundle** in `config/bundles.php` if Flex did not:

   ```php
   Emagma\SyliusMcpPlugin\EmagmaSyliusMcpPlugin::class => ['all' => true],
   ```

2. **Enable the Sylius API in every environment** the MCP server talks to.
   The Sylius standard enables it in `dev` only; in `config/packages/sylius_api.yaml`:

   ```yaml
   sylius_api:
       enabled: true
   ```

3. **Set the environment**: `MCP_SERVICE_ACCOUNT_EMAIL` and `MCP_SERVICE_ACCOUNT_PASSWORD`.
4. **Create the service account**: `bin/console sylius-mcp:service-account`.
5. **Clear the caches**, API Platform's metadata pools included (they survive `cache:clear`):

   ```bash
   bin/console cache:clear
   bin/console cache:pool:clear api_platform.cache.metadata.resource \
       api_platform.cache.metadata.resource_collection api_platform.cache.metadata.operation \
       api_platform.cache.metadata.property cache.system
   ```

### Environment variables

| Variable | Purpose |
|---|---|
| `MCP_SERVICE_ACCOUNT_EMAIL` | Email of the service account |
| `MCP_SERVICE_ACCOUNT_PASSWORD` | Its password |

The MCP server holds the same two values: it signs in with them.

### Configuration

None for the shop.
The `emagma_sylius_mcp` configuration key exists for other bundles (the shop half of an MCP extension adds its lean groups and filters there): see the [developer guide](developer-guide.md).

## The service account

```bash
bin/console sylius-mcp:service-account [--email=...] [--password=...]
```

- **What it does**: creates the Sylius admin user, or updates the existing one with that email: password set again, account enabled.
- **Options**: `--email` and `--password` default to `MCP_SERVICE_ACCOUNT_EMAIL` and `MCP_SERVICE_ACCOUNT_PASSWORD`.
  Without either, the command fails.
- **Roles**: `ROLE_API_ACCESS`, all the Admin API asks for.
  It never holds `ROLE_ADMINISTRATION_ACCESS`, and removes it from an account that had it: a machine identity whose password sits in an environment variable must not open the back-office.
- **Idempotent**: run it again after every database reset (after every database reset).

Every MCP user acts through this one account.
Who really asked is known by the MCP server (its grants, its audit), not by Sylius.

## Who may call it

- The lean payloads and the filters are on Sylius's own admin routes, under `/api/v2/admin`: the admin API firewall and `ROLE_API_ACCESS`, as for any Sylius admin resource.
- The capabilities endpoint is under `/api/v2/admin` too, with the same protection.
- The plugin does not touch the firewall or the admin's login: the back-office keeps its `form_login`, and any SSO stays the integrator's business.
  The MCP server's own identity provider plays no part in the shop.

## Limits and things to know

- **After installing or upgrading the plugin, clear API Platform's metadata pools** (command above).
  Otherwise the filters are not attached to the operations, and the collections answer `200` while ignoring every parameter.
- **A shop that overrides the Sylius models** (`App\Entity\Product\Product`) needs nothing more: the lean groups are declared on Sylius's classes and inherited.
- **`taxTotal` and `shippingTotal` cannot be filtered on**: they are not columns but sums over adjustments.
  `total = itemsTotal + adjustmentsTotal`, and Sylius already filters on `total[gte|lte]`.
- **Order totals of several currencies share one collection**: pair `itemsTotal` / `adjustmentsTotal` with a currency or channel filter.
- **`rootOnly=false` is a no-op**, not "children only".

## See also

- [Developer guide](developer-guide.md)
- The user guide of `emagma/mcp-sylius-bundle`: the server side
