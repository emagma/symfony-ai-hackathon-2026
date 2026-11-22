# Sylius MCP event plugin: user guide

`emagma/sylius-mcp-event-plugin` tells the MCP server what happens in the shop.
When a customer is created, the shop sends a signed webhook to the server.

The server keeps it in a journal that its background agents read.
The receiving side is `emagma/mcp-sylius-events-bundle` (see its user guide).

This guide is for the person who installs the plugin in a shop.
There is nothing to use in the back-office.

## What it does

- **One event**: `customer.created`, whatever way the customer came to exist (registration, guest checkout, back-office, API, import).
- **Identifiers only**: the webhook carries the customer's `id` and its `createdAt` date, never the email or the name.
- **Signed**: Symfony Webhook signs every webhook with the secret both sides share.
- **Asynchronous**: the webhook leaves through a Messenger transport, so a slow or stopped server never slows down a registration.
- **Off until configured**: without a URL or a secret, nothing is sent.

The server reads the rest of the customer through the Admin API, under its own rules.

It runs on Sylius 1.14 and on Sylius 2.x.

## Set it up

### Requirements

- PHP 8.3 or later
- `sylius/sylius` 1.14 or 2.x
- `symfony/messenger`, `symfony/webhook` and `symfony/remote-event` (6.4, 7.4 or 8.1)
- A Messenger worker that consumes the transport the webhooks are routed to

### Install

```bash
composer require emagma/sylius-mcp-event-plugin
```

Register the plugin in `config/bundles.php` if Flex did not:

```php
Emagma\SyliusMcpEventPlugin\EmagmaSyliusMcpEventPlugin::class => ['all' => true],
```

### Environment variables

- **`MCP_EVENT_WEBHOOK_URL`**: where the MCP server receives the events, for example `http://mcp-server/webhook/sylius`.
- **`MCP_EVENT_WEBHOOK_SECRET`**: the secret shared with the MCP server, the same value on both sides.

Empty or unset, either one turns the plugin off.

### Configuration

The key is `emagma_sylius_mcp_event`:

| Key | Default |
|---|---|
| `url` | `%env(default::MCP_EVENT_WEBHOOK_URL)%` |
| `secret` | `%env(default::MCP_EVENT_WEBHOOK_SECRET)%` |
| `transport` | `main` |

`transport` is the Messenger transport the webhooks are routed to.
`main` is the one the Sylius standard defines.

A worker must consume it:

```bash
bin/console messenger:consume main
```

The environment variables are enough for most shops.

## What the server receives

A `POST` to the URL, with the default headers of Symfony Webhook:

- `Webhook-Event`: the event name.
- `Webhook-Id`: a UUID v7, unique per event.
- `Webhook-Signature`: `sha256=` and an HMAC-SHA256, with the secret, of the event name, the event id and the body.

The body is JSON:

```json
{"id": "42", "createdAt": "2026-11-21T10:15:00+00:00"}
```

The server records an event delivered twice only once, thanks to the `Webhook-Id`.

## If nothing arrives

Check, in order:

- Both variables are set in the shop.
- The secret is the same as the server's.
- A worker consumes the transport.
- The URL reaches the server from the shop's host or container.

A failed delivery follows the retry policy of the transport, then its failure transport if it has one.

## See also

- [Developer guide](developer-guide.md)
- The user guide of `emagma/mcp-sylius-events-bundle`: the receiving side
