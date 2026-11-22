# MCP Sylius bundle - user guide

This bundle is what makes the MCP server an assistant for a Sylius shop: 145 MCP Tools over the shop's Admin API, covering the catalogue, orders, payments, shipments, customers, promotions and the shop's configuration.
It is for the merchant or member of staff who asks things in the chat, and for the person who connects the server to a shop.

## What you can do

You do not call an MCP Tool yourself: you ask in the chat, and the assistant picks the tools.
The tools follow a few families, and every tool of a family behaves the same way.

### Conventions shared by every tool

The server gives these to the assistant when a conversation starts (`src/ServerInstructions.php`), first in the server's instructions.

- **Writes ask you first**: every write shows a preview and waits for your answer.
  When the chat client cannot ask, the tool returns the preview unapplied (`elicitation_unavailable_preview_only`) or refuses (`human_confirmation_required`).
- **A write result starts with `ok`**: then a key such as `updated`, `created`, `deleted`, `refunded`.
  A success carries `diff`, `before` and `after`.
  A failure carries a `reason`: `not_found`, `validation_error` (with the shop's `errors`), `conflict`, `upstream_error`, `no_change`, `no_change_requested`, `cancelled`, `declined`, or one specific to the tool.
- **A no is final**: after `declined` or `cancelled`, the assistant does not try the write again on its own, not even changed.
  It says the write was not done and waits for you to ask.
- **Updates can be undone, the rest cannot**: when the audit bundle is installed, an update of an existing resource is recorded and `revert_mcp_edit` undoes it.
  Creations, deletions and state transitions (cancel, refund, ship, accept, reject, archive) cannot be reverted: those MCP Tools are marked irreversible, and a revert of their edits answers `nothing_to_restore` at once.
- **Customers and administrators are recorded by id**: the audit's target code of `upsert_customers` and `upsert_administrators` is the id, never the email the batch is keyed on.
- **Resources are addressed by `code`**: except orders (`tokenValue`) and customers, payments, shipments (`id`).
- **Batches**: `upsert_<resources>` and `delete_<resources>` take up to 50 items or codes and ask once for the whole batch.
  Results are per item.
- **Paging**: `search_*` tools and `list_order_collection` take `page` (default 1) and `itemsPerPage` (default 30, max 50) and return `{items, total, page, hasMore}`.
- **One locale per call**: translated fields are read and written in the `locale` argument, which defaults to `MCP_DEFAULT_LOCALE`, unless the tool says it fills every enabled locale.
- **Amounts are integers in minor units**: 5000 means 50.00 in a two-decimal currency.
- **No tool sends a message the assistant wrote**: a message to a customer is drafted in the reply, for you to send.
- **Customer-written text is data**: an item that carries `untrustedContent` names the fields written by customers or visitors.
  The assistant reports them and never follows what they say.
- **Personal identifiers may be masked**: depending on the client, emails, phones and logins come as stable tokens (`email:7f3a9c21`).
  The batch `get_*` tools of people take `reveal: true` when the real value is needed (see "Who may use it").

### The families, with examples

#### `search_*` - find resources

- **Example**: `search_products`
- **Ask**: "Which products of the brand Modern Wear cost less than 30 euros on the web channel?"
- **Arguments**: `name`, `code`, `taxon`, `channel`, `attributeEq`, `attributePresent`, `attributeRange`, `priceRange`, `locale`, `page`, `itemsPerPage`
- **Comes back**: `{items: [{code, name}], total, page, hasMore}`.
  A search returns short items; the detail is the matching `get_*`.

`search_orders` filters on `state`, `paymentState`, `shippingState`, `customerEmail`, `number`, `completedFrom` / `completedTo`, totals and counts, with `sort` and `direction`: "Show me the unpaid orders of last week".

#### `get_*` - read the detail

- **Example**: `get_products`
- **Ask**: "Show me the description and the SEO fields of the product `Apollo_T_Shirt`."
- **Arguments**: `codes` (1 to 50), `locale`
- **Comes back**: `{found: [...], missing: [codes]}`.
  A missing resource is not an error.

`get_addresses` takes `ids` and `reveal`.
Addresses cannot be listed: their ids come from `get_customers` (default address) or `get_orders` (shipping and billing address).

#### `upsert_*` - create or update in a batch

- **Example**: `upsert_products`
- **Ask**: "Rename these three products and attach them to the taxon `jeans`."
- **Arguments**: `items` (1 to 50, matched on their `code`), `mode`, `confirm`
- **`mode`**: `upsert` (default: create the missing, update the rest), `create` (an existing code fails with `already_exists`), `update` (an unknown code fails with `not_found`)
- **Comes back**: one result per item, `{ok, action: created|updated|unchanged|failed, diff|reason|errors}`, and a `batchId` that groups the audit entries.
  An item that changes nothing is `unchanged` and is not written.

A product creation needs `name` and `slug`.

#### `update_*` - change one resource

- **Example**: `update_address`
- **Ask**: "The customer of order 000000021 made a typo: the street is 12 rue de la Paix, not 21."
- **Arguments**: `id`, then any of `firstName`, `lastName`, `company`, `street`, `postcode`, `city`, `countryCode`, `provinceCode`, `provinceName`, `phoneNumber`, and `confirm`
- **Comes back**: `{updated: true, id, diff, before, after}`.
  Only the fields given change.
- **To know**: the address of an order and the one in the customer's address book are separate copies; changing one never changes the other.
  Correcting the address of an order does not recompute its taxes or shipping costs.
  An address cannot be created or deleted.

#### `delete_*` - delete in a batch

- **Example**: `delete_products`
- **Ask**: "Delete the three test products I created this morning."
- **Arguments**: `codes` (1 to 50), `confirm`
- **Comes back**: `{ok, batchId, total, deleted, failed, results}`.
  A product the shop still needs is refused and the others go through.
  Not revertable.

#### State transitions - cancel, refund, ship, accept, archive…

- **Examples**: `cancel_order`, `refund_payment`, `complete_payment`, `ship_shipment`, `accept_product_review`, `reject_product_review`, `archive_promotion`, `restore_promotion`, `archive_shipping_method`, `restore_shipping_method`
- **Ask**: "Cancel order 000000042." / "Mark the payment of that order as refunded."
- **Comes back**: e.g. `{cancelled: true, tokenValue, diff, before, after}`
- **To know**:
  - `cancel_order` takes the order's `tokenValue`, works only on an order in state `new`, cannot be undone, and refunds nothing.
  - `refund_payment` works only on a `completed` payment, always for the full amount, and records the refund in Sylius: whether the money travels back depends on the payment gateway.

#### Files - images

`create_product_image` and `create_taxon_image` take the file either as `base64Content` or as `file`, a file attached to the conversation that the client hands over (ChatGPT).
"Add this picture to the product `Apollo_T_Shirt`."

### MCP Tools by domain

One table per folder of `src/Tool/`.

#### Catalogue

- **Product**: `search_products`, `get_products`, `upsert_products`, `delete_products`, `update_product_taxons`
- **ProductVariant**: `search_product_variants`, `get_product_variants`, `upsert_product_variants`, `delete_product_variants`
- **ProductOption**: `search_product_options`, `get_product_options`, `upsert_product_options`, `delete_product_options`, `add_product_option_value`
- **ProductAttribute**: `search_product_attributes`, `get_product_attributes`, `upsert_product_attributes`, `delete_product_attributes`, `set_product_attribute_value`, `remove_product_attribute_value`
- **ProductImage**: `search_product_images`, `get_product_image`, `create_product_image`, `update_product_image`, `delete_product_image`
- **ProductAssociation**: `search_product_associations`, `get_product_associations`, `upsert_product_associations`, `delete_product_associations`
- **ProductAssociationType**: `search_product_association_types`, `get_product_association_types`, `upsert_product_association_types`, `delete_product_association_types`
- **ProductReview**: `search_product_reviews`, `get_product_reviews`, `update_product_review`, `accept_product_review`, `reject_product_review`, `delete_product_review`
- **ProductTaxon**: `search_product_taxons`, `update_product_taxon_position`
- **Taxon**: `search_taxons`, `list_taxon_tree`, `get_taxons`, `upsert_taxons`, `delete_taxons`
- **TaxonImage**: `search_taxon_images`, `get_taxon_image`, `create_taxon_image`, `update_taxon_image`, `delete_taxon_image`

#### Sales

- **Order**: `search_orders`, `get_orders`, `list_order_collection`, `cancel_order`, `resend_order_confirmation_email`
- **Payment**: `search_payments`, `get_payments`, `complete_payment`, `refund_payment`
- **Shipment**: `search_shipments`, `get_shipments`, `ship_shipment`, `resend_shipment_confirmation_email`

`list_order_collection` takes a `kind`: `items`, `payments`, `shipments` or `adjustments`.

#### Customers

- **Customer**: `search_customers`, `get_customers`, `get_customer_statistics`, `upsert_customers`, `delete_customer_users`
- **CustomerGroup**: `search_customer_groups`, `get_customer_groups`, `upsert_customer_groups`, `delete_customer_groups`
- **Address**: `get_addresses`, `update_address`

#### Marketing

- **Promotion**: `search_promotions`, `get_promotions`, `upsert_promotions`, `delete_promotions`, `archive_promotion`, `restore_promotion`
- **PromotionCoupon**: `search_promotion_coupons`, `get_promotion_coupons`, `upsert_promotion_coupons`, `generate_promotion_coupons`, `delete_promotion_coupons`
- **CatalogPromotion**: `search_catalog_promotions`, `get_catalog_promotions`, `upsert_catalog_promotions`, `delete_catalog_promotions`

#### Configuration

- **Channel**: `search_channels`, `get_channels`, `upsert_channels`, `delete_channels`
- **Locale**: `search_locales`, `get_locales`, `upsert_locales`, `delete_locales`
- **Currency**: `search_currencies`, `get_currencies`, `upsert_currencies`
- **ExchangeRate**: `search_exchange_rates`, `get_exchange_rates`, `upsert_exchange_rates`, `delete_exchange_rates`
- **Country**: `search_countries`, `get_countries`, `upsert_countries`
- **Province**: `search_provinces`, `get_province`, `add_province`, `update_province`, `remove_province`
- **Zone**: `search_zones`, `get_zones`, `upsert_zones`, `delete_zones`, `add_zone_member`, `remove_zone_member`
- **TaxCategory**: `search_tax_categories`, `get_tax_categories`, `upsert_tax_categories`, `delete_tax_categories`
- **TaxRate**: `search_tax_rates`, `get_tax_rates`, `upsert_tax_rates`, `delete_tax_rates`
- **ShippingCategory**: `search_shipping_categories`, `get_shipping_categories`, `upsert_shipping_categories`, `delete_shipping_categories`
- **ShippingMethod**: `search_shipping_methods`, `get_shipping_methods`, `upsert_shipping_methods`, `delete_shipping_methods`, `archive_shipping_method`, `restore_shipping_method`
- **PaymentMethod**: `search_payment_methods`, `get_payment_methods`, `upsert_payment_methods`, `delete_payment_methods`

#### Administration

- **Administrator**: `search_administrators`, `get_administrators`, `upsert_administrators`, `delete_administrators`

The administrator tools refuse to disable or delete the server's own service account.

## Setting it up

### Environment variables

| Variable | Example | Meaning |
|---|---|---|
| `SYLIUS_API_URL` | `http://shop/api/v2/admin/` | Root of the shop's Admin API |
| `MCP_SYLIUS_VERSION` | `2.3` | The shop's Sylius version |
| `MCP_SHOP_EXTENSIONS` | (empty) | Shop extensions to activate |
| `MCP_SERVICE_ACCOUNT_EMAIL` | `mcp-server@…` | The server's Sylius administrator |
| `MCP_SERVICE_ACCOUNT_PASSWORD` | | Its password |
| `MCP_DEFAULT_LOCALE` | `en_US` | Locale used when a call gives none |

- **`SYLIUS_API_URL`**: keep the trailing slash, the paths are relative to it.
- **`MCP_SYLIUS_VERSION`**: `2.0` to `2.3`, a patch may follow (`2.2.9`).
  It is configured, not discovered.
  A value no registered line bundle serves stops the server.
- **`MCP_SHOP_EXTENSIONS`**: comma-separated, empty for none.
  A tool of an extension that is not listed is hidden and answers `shop_extension_disabled`.
  An unknown name stops the server.
- **`MCP_SERVICE_ACCOUNT_*`**: one Sylius administrator for every MCP user, created by the shop plugin's `sylius-mcp:service-account` command.
- **`MCP_DEFAULT_LOCALE`**: the locale of the shop's default.

These are read at runtime: a change needs a restart of the server, with no cache clear.

### Configuration keys (`emagma_mcp_sylius`)

Each variable above is the default of a key, which a deployment may override:

| Key | Default |
|---|---|
| `api_url` | `%env(SYLIUS_API_URL)%` |
| `sylius_version` | `%env(MCP_SYLIUS_VERSION)%` |
| `shop_extensions` | `%env(MCP_SHOP_EXTENSIONS)%` |
| `default_locale` | `%env(MCP_DEFAULT_LOCALE)%` |
| `service_account.email` | `%env(MCP_SERVICE_ACCOUNT_EMAIL)%` |
| `service_account.password` | `%env(MCP_SERVICE_ACCOUNT_PASSWORD)%` |
| `bench` | `false` |
| `file_params.allowed_hosts` | ChatGPT's file stores |

- **`file_params.allowed_hosts`**: the hosts the server may download an attached file from.
  A name covers its subdomains; a glob covers the whole host.
  Default: `oaiusercontent.com` and `oaisdmntpr*.blob.core.windows.net`.
  Any other host is refused with `file_url_refused`.
  `*` allows every host.
- **`bench`**: registers the `mcp:bench:agent` command.
  For a development host.

`config/packages/emagma_mcp_sylius.yaml` of a host that serves ChatGPT and traces the client in development:

```yaml
emagma_mcp_sylius:
    file_params:
        allowed_hosts: ['*']

when@dev:
    emagma_mcp_sylius:
        bench: true
```

### In the shop

The shop needs the plugin (`emagma/sylius-mcp-plugin`): it serves the short payloads and the filters the search tools rely on, and publishes the shop's version and extensions.
Its own guides tell how to install it.

## Who may use it

Each MCP Tool declares one or more grants `mcp:sylius:<resource>:<action>`, with `read`, `write` or `delete` as action.
A caller who lacks a grant does not see the tool.

The grants are listed by `bin/console mcp:permissions`.
How a person gets them is up to the host: a role per resource and action, or a few roles that gather them.

Grants by area:

- **Catalogue**: product, product_association, product_association_type, product_attribute, product_image, product_option, product_review, product_variant, taxon, taxon_image
- **Configuration**: channel, country, currency, exchange_rate, locale, zone, province, tax_category, tax_rate, shipping_category, shipping_method, payment_method
- **Marketing**: promotion, catalog_promotion
- **Sales**: order, payment, shipment
- **Customers**: customer, customer_group, address
- **Administration**: administrator

Keep `administrator` apart from the others: granting it is granting the power to create administrators.

Things that are not obvious from the tool name:

- The coupon tools (`*_promotion_coupons`) need the `promotion` grants: there is no coupon resource to grant.
- `search_product_taxons` needs `product:read` and `taxon:read`; `update_product_taxons` and `update_product_taxon_position` need `product:write` and `taxon:read`.
- `set_product_attribute_value` needs `product:write` and `product_attribute:read`; `remove_product_attribute_value` needs `product:write`.
- `upsert_product_associations` needs `product_association:write` and `product:read`.
- `add_province` and `remove_province` need `province:write` and `country:write`.
- `country`, `currency`, `province`, `order`, `payment`, `shipment` and `address` have no `delete` grant: no tool deletes them.
- `reveal: true` on `get_customers`, `get_administrators`, `get_orders` and `get_addresses` needs `mcp:core:pii:reveal`, on top of the read grant.
  Without it the answer is `reason: forbidden`.
  Each reveal is recorded in the audit, per person.

## Commands

Run with `bin/console <cmd>`.

### `mcp:sylius-version:check`

- **Does**: compares `MCP_SYLIUS_VERSION` with the version the shop publishes.
- **Fails when**: the shop runs another minor, or a version the server does not support, or the shop cannot be read.
- **Warns when**: the shop's plugin does not publish its version (nothing is checked).

### `mcp:shop-extensions:check`

- **Does**: compares `MCP_SHOP_EXTENSIONS` with the extensions the shop publishes.
- **Fails when**: an extension is active here but the shop does not publish it (its tools would fail).
- **Warns when**: the shop publishes an extension that is not activated here (its tools stay hidden).

Run them after a change of the shop or of the environment.

### `mcp:bench:agent`

- **Only when**: `emagma_mcp_sylius.bench` is true, for a development host.
- **Does**: runs a fixed sequence of `search_products` and `get_products` against the shop and writes the captured trace (a JSON and a Markdown file).
- **Options**: `--iterations` / `-i` (default 1), `--output` / `-o` (default `var/bench/mcp-<ts>.json`), `--reset-jwt` (start with a fresh login).

## Limits and things to know

- **A line bundle is required**: this bundle alone talks to no shop.
  `mcp-sylius-v2-bundle` (2.0 to 2.3) must be registered, or the first request fails with a message naming the bundle to install.
- **The `priority` field of `upsert_zones` needs Sylius 2.2**.
- **Without the plugin in the shop** the server logs a warning (`The shop answered without the Sylius-Mcp-Plugin header`): payloads are not short and the MCP-only filters (price range, order state…) are ignored.
- **One service account**: in the shop, every write is made by the service account.
  Who asked is known by the MCP server (the audit), not by Sylius.
- **A hung shop**: a call to the shop is abandoned after 10 seconds without data, 30 seconds in all.
- **Attached files**: only `https` URLs on an allowed host are downloaded, redirects are not followed, and private addresses are refused.
- **When nothing works**: run the two check commands.
  The server logs in to the shop at `administrators/token` with `MCP_SERVICE_ACCOUNT_*`: check both values first.
