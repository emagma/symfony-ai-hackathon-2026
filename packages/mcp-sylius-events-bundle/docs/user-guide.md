# MCP Sylius Events Bundle: user guide

This bundle lets your server know what happens in the shop.

The shop sends a signed webhook for each event, for example a customer account that was created.
The server keeps each event once, in a journal.
It then says so at once, so that a background agent waiting for that event wakes up and reads the journal.

It is for the person who configures the deployment.
There is nothing to use in the chat: the bundle brings no MCP tool and no screen.

## What it does

- It accepts the shop's events on `/webhook/sylius`.
- It records each event once in the table `mcp_shop_event`.
- It dispatches `ShopEventRecorded`, so that whoever waits for the event reacts.

An event holds:

- its name, for example `customer.created`,
- the id of what it is about,
- the payload the shop sent (identifiers and dates only),
- when the server received it.

Never the customer's data.
Whoever reads an event fetches the rest through the shop's Admin API.

## Set it up

### On the server

| Variable | Meaning |
|---|---|
| `MCP_EVENT_WEBHOOK_SECRET` | The secret shared with the shop. Every webhook is checked with it. |

Then:

- Load Symfony Webhook's route under `/webhook` (`@FrameworkBundle/Resources/config/routing/webhook.php`).
- Create the table with the bundle's migration (`doctrine:migrations:migrate`).

The bundle has no configuration key.

### In the shop

This bundle is only the receiving half.
The plugin `emagma/sylius-mcp-event-plugin` must be installed in the shop, with two variables:

| Variable (shop) | Meaning |
|---|---|
| `MCP_EVENT_WEBHOOK_URL` | Where the server receives, for example `https://mcp.example.com/webhook/sylius` |
| `MCP_EVENT_WEBHOOK_SECRET` | The same secret as on the server |

Without them the shop sends nothing.
The webhooks leave through the shop's Messenger queue: its worker must run.

## Who may use it

Nobody calls it: it exposes nothing to MCP callers.

The endpoint is not behind your login.
The signature, computed with the shared secret, is its only guard.
Keep the secret private.

## Good to know

- **One event for now**: `customer.created` is the only event the shop's plugin sends.
- **A webhook delivered twice is recorded once**: the id the shop gave the event is unique in the journal.
- **A webhook delivered again wakes no one.**
- **The journal is never purged** by this bundle.

## Nothing arrives?

Check, in this order:

1. The plugin is installed in the shop.
2. Both shop variables are set.
3. The secret is the same on both sides.
4. The shop's worker runs.
5. The URL reaches the server from the shop's container.
