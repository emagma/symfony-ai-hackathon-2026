# MCP Sylius Screens Bundle: user guide

Two screens of a Sylius shop, shown in the conversation by the MCP screens bundle.

- **`product_variants`**: the prices and stocks of a product's variants.
- **`address`**: one address of an order or of a customer.

They are for the staff who correct such values in the chat.
The user sees the current values, edits them in a form and saves them, after a confirmation.

## What you can do

Both screens are opened with the MCP Tool `open_screen` (`screen`, `params`).
The tool itself is described in the user guide of `emagma/mcp-screens-bundle`.

| Screen | Shows | Saves with |
|---|---|---|
| `product_variants` | Prices and stocks of a product's variants | `upsert_product_variants` |
| `address` | One address | `update_address` |

### product_variants

- **For**: correcting several prices and stocks of one product in one go.
- **What to type**: "Open the prices and stocks of the variants of product CAP."
- **Params**: `productCode` (required), `channelCode` (optional).
  Without a channel, the first channel in which the variants have a price is shown.

What it shows:

- One line per variant: name and code, price in the channel, stock on hand, stock on hold.
- Prices in units of the channel's base currency (`99.05`), not in minor units.
- A selector to change the channel, when the variants have prices in several channels.

Editing and saving:

- "Edit prices and stocks" turns the table into a form.
- A price accepts `12.5` and `12,50`.
- A stock is a whole number, zero or more.
- The stock of a variant that is not tracked is shown as "not tracked" and cannot be typed.
- Only the variants whose price or stock changed are written.
- They go in one `upsert_product_variants` call in mode `update`, confirmed once.
- The message says how many variants were saved.

Refusals:

- "This screen needs params.productCode."
- "The product "X" does not exist or has no variant."
- "The variants of "X" have no price in any channel yet."

### address

- **For**: correcting one address.
- **What to type**: "Show me the shipping address of order 000000025, the postcode is wrong."
- **Params**: one of the following.

| Params | Address shown |
|---|---|
| `orderToken`, `kind` | Of an order; `kind` is `shipping` (default) or `billing` |
| `customerId` | The default address of a customer |
| `addressId` | That address |

What it shows: first name, last name, company, street, postcode, city, province, country code, phone.

Editing and saving:

- First name, last name, street, postcode, city and country code are required.
- The country code is two capital letters (`FR`).
- Only the changed fields are sent, with `update_address`, confirmed by the user.
- What the shop refuses comes back under the field concerned.

Two things to know:

- **Shipped order**: its shipping address is shown but locked. Its billing address stays editable.
- **Separate copies**: the address of an order and the one of a customer are separate copies in Sylius.
  The screen says so: changing one changes neither the customer's other addresses, nor the taxes and shipping of an order.

### Messages after a save

| Message | Meaning |
|---|---|
| "N variant(s) saved." / "Address saved." | Written |
| "Nothing changed: nothing was saved." | The values are the current ones |
| "You did not confirm: nothing was saved." | Confirmation declined or cancelled |
| "This client cannot ask you to confirm a write: nothing was saved." | The client has no confirmation |
| "Nothing was saved (reason)." | The shop or the server refused |

## Setting it up

Nothing to configure: no environment variable, no configuration key.

The bundle needs `emagma/mcp-screens-bundle` and `emagma/mcp-sylius-bundle` registered.
It reaches the shop through the configuration of the latter.

## Who may use it

On top of `mcp:core:screen:read`, which opens `open_screen`, each screen checks its own grants.

- **`product_variants`**
  - **To see**: `mcp:sylius:product_variant:read`, `mcp:sylius:channel:read`
  - **To save**: `mcp:sylius:product_variant:write`
- **`address`**
  - **To see**: `mcp:sylius:address:read`, `mcp:sylius:order:read`, `mcp:sylius:customer:read`, `mcp:core:pii:reveal`
  - **To save**: `mcp:sylius:address:write`

Two rules follow:

- Without the write grant, the screen is shown read-only.
- `address` shows a person's address in clear, whatever the client: this is why it needs `mcp:core:pii:reveal`.

## Commands

None.

## Limits and things to know

- **50 variants at most**: beyond, the screen shows the first ones and says so.
- **One channel at a time**, and only channels in which at least one variant already has a price.
  A `channelCode` without any price falls back to the first channel.
  The screen does not give a product its first price in a new channel.
- **An empty price or stock field** is left as it is: the screen does not remove a price.
- **Country and province are typed**, not picked from a list.
  The screen edits the province name, not a province code.
- **A customer without a default address** answers "This customer has no default address."
- **What is saved is journaled** like the same `upsert_product_variants` or `update_address` call.
