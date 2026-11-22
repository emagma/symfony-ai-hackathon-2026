---
name: open-new-channel
description: Open a new sales channel in the Sylius shop (a marketplace, a second storefront, a B2B channel) step by step - destination countries, currency and languages, taxes, shipping and payment methods, products and their prices on the channel. Use it when the merchant wants to sell on a new channel, a new country or a marketplace.
metadata:
  version: "1.0.0"
  requires: "sylius:channel:write sylius:zone:write sylius:country:read sylius:currency:read sylius:locale:read sylius:tax_rate:read sylius:shipping_method:write sylius:payment_method:write sylius:product:write sylius:product_variant:write"
---
# Open a new sales channel

Lead the merchant from "I want to sell there" to a channel that can take an order. The shop already has channels: every choice starts from what exists.

## Rules

- One question at a time. Propose the answer you would pick from what the shop already has, and let the merchant confirm or change it.
- Read before you ask: never ask for something a tool can tell you.
- Every write is confirmed by the merchant when the tool asks. A refused or declined write stops the step: say what is missing, do not work around it.
- Keep a running list of what is done and what is left, and show it after each step.

## Steps

### 1. What the channel is for

Ask: a storefront, a marketplace, or B2B. Sylius has no channel "type"; the use sets the conventions:

| Use | Code | Hostname |
|---|---|---|
| Storefront | `WEB_<COUNTRY>` | the site's own |
| Marketplace | `MKT_<NAME>` | none |
| B2B | `B2B_<COUNTRY>` | the portal's, if any |

Read `search_channels` first: propose a code and a name in the style of the existing ones.

### 2. Destination countries

`search_countries` for what the shop knows, `search_zones` for the zones that already cover them. Reuse a zone that fits; otherwise create one (`upsert_zones`, then `add_zone_member`).

### 3. Currency and languages

`search_currencies`, `search_locales`. One base currency, one default locale, the others optional. If the base currency differs from the other channels', ask whether prices are converted: an exchange rate may be needed (`search_exchange_rates`).

### 4. Taxes

`search_tax_rates` for the rates of the destination zone. Ask the default tax zone and keep the tax calculation strategy of the existing channels unless told otherwise. A missing rate is reported, not invented: tax rates are the merchant's accountant's call.

### 5. Shipping and payment

`search_shipping_methods`, `search_payment_methods`. Propose the existing methods that make sense for the countries; a marketplace often needs none of the shop's own.

### 6. Create the channel, switched off

`upsert_channels` with `mode: "create"` and `enabled: false`: code, name, hostname, base currency, default locale, currencies, locales, countries, default tax zone, tax calculation strategy. Nothing can be attached to a channel that does not exist yet, and a channel switched off takes no order while it is incomplete.

### 7. Open the methods on the channel

`upsert_shipping_methods` and `upsert_payment_methods` with the channel added to their `channelCodes`. **`channelCodes` replaces the list**: read the current channels of each method and send them all plus the new one.

### 8. Products

Ask which products: a category (`search_taxons`, then `search_products`), or a list. Then `upsert_products` by batches of 50 with the new channel added to `channelCodes`. Same warning: **`channelCodes` replaces the list**. Read each product's channels (`get_products`) and send them all plus the new one, or the product leaves its other channels.

### 9. Prices

Sylius wants a price per variant on every channel of its product. Propose a rule and state it back before writing: "the price of channel X, plus 5 %, rounded to .90".

- Read the variants of the chosen products (`search_product_variants` with `product`, then `get_product_variants` for `pricing`).
- Write with `upsert_product_variants`, `mode: "update"`, 50 items per call, `pricing: {<new channel>: <price>}`: pricing is merged, the other channels keep theirs. Amounts are in minor units.
- For one product the merchant wants to check by eye, open the `product_variants` screen (`open_screen`, `params: {productCode, channelCode}`).

### 10. Switch on and sum up

Ask before switching on: `upsert_channels`, `mode: "update"`, `enabled: true`. Then sum up: what is ready, what is missing (a tax rate, a price, a method), and what to check in the back office.

## Updates

This skill is served by the shop's MCP server. When `list_skills` reports a version of `open-new-channel` above 1.0.0, tell the merchant a newer version exists and that `get_skill` returns it.
