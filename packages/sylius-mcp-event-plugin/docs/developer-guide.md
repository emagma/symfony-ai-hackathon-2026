# Sylius MCP event plugin: developer guide

- **Composer name**: `emagma/sylius-mcp-event-plugin`
- **Namespace**: `Emagma\SyliusMcpEventPlugin`
- **Plugin class**: `EmagmaSyliusMcpEventPlugin`
- **Config alias**: `emagma_sylius_mcp_event` (`url`, `secret`, `transport`)
- **Depends on**: `sylius/sylius` ^1.14 or ^2.0, Doctrine ORM, `symfony/messenger`, `symfony/webhook`, `symfony/remote-event`, `symfony/uid`. PHP 8.3.
- **Depended on by**: nothing. The receiving half is `emagma/mcp-sylius-events-bundle`, on the MCP server.

## How it is built

- **`EventListener\CustomerCreatedListener`**: Doctrine `postPersist`, a new customer becomes `customer.created`.
- **`Relay\ShopEventRelay`**: wraps an event in a `SendWebhookMessage` and dispatches it, if configured.
- **`DependencyInjection\EmagmaSyliusMcpEventExtension`**: loads `config/services.php`, routes the webhook message to the transport.
- **`DependencyInjection\Configuration`**: `url`, `secret`, `transport`.

Only `ShopEventRelay` is public API.
The rest is `@internal`.

Service ids are prefixed by the alias:

- `emagma_sylius_mcp_event.relay`, aliased by its class `ShopEventRelay`.
- `emagma_sylius_mcp_event.listener.customer_created`, tagged `doctrine.event_listener` on `postPersist`.

The files:

- `src/`: the code.
- `config/services.php`: the explicit service definitions.
- `tests/Unit/`: the tests.
- `docs/`: these guides.

## The flow of one event

1. Doctrine persists a `CustomerInterface`.
2. `CustomerCreatedListener::postPersist()` reads its id. An object of another class, or one without an id, is ignored.
3. It calls `ShopEventRelay::relay('customer.created', ['id' => '<id>', 'createdAt' => '<ATOM date>'])`.
4. `ShopEventRelay` returns at once when the URL or the secret is empty (`isConfigured()`).
5. Otherwise it dispatches a `SendWebhookMessage` on `messenger.default_bus`: a `Subscriber(url, secret)` and a `RemoteEvent(name, Uuid::v7(), payload)`.
6. The extension has routed `SendWebhookMessage` to the configured transport (`main` by default).
7. The worker that consumes it hands the message to the handler of Symfony Webhook, which signs and sends the request.

A Doctrine listener is used rather than a Sylius resource event.
It sees every way a customer comes to exist, and it is the same on Sylius 1.14 and 2.x.

## Extension points

There is no tag and no interface to implement.
To relay another event, write a listener that calls `ShopEventRelay::relay()`:

```php
// A hypothetical event: the plugin sends none but customer.created.
$this->relay->relay('order.completed', [
    'id' => (string) $order->getId(),
    'completedAt' => $order->getCheckoutCompletedAt()?->format(\DATE_ATOM),
]);
```

- **Identifiers and dates only** (`array<string, scalar|null>`): the server reads the rest through the Admin API, under its own privacy rules.
- **`id` is the subject**: the receiving bundle stores `payload['id']` as the id of what the event is about.
- **The name** is the `RemoteEvent` name the server journals, and the agents subscribe to it there.

The webhook (event name, payload, signature headers) is read by `emagma/mcp-sylius-events-bundle`.
Its format is public: do not change it without changing both sides.

## Configuration reference

- **`url`**
  - **Default**: `%env(default::MCP_EVENT_WEBHOOK_URL)%`
  - **Effect**: argument 1 of `ShopEventRelay`, empty sends nothing.
- **`secret`**
  - **Default**: `%env(default::MCP_EVENT_WEBHOOK_SECRET)%`
  - **Effect**: argument 2 of `ShopEventRelay`, empty sends nothing.
- **`transport`**
  - **Default**: `main`, cannot be empty.
  - **Effect**: prepended to `framework.messenger.routing` for `Symfony\Component\Webhook\Messenger\SendWebhookMessage`.

`prepend()` reads the configuration itself, with `getExtensionConfig()`.
So `transport` must be set in the shop's configuration files, not by the `prepend()` of another bundle that runs later.

## Data

None in the shop.

## Tests and quality

- **`tests/Unit/CustomerCreatedListenerTest.php`**: a new customer is relayed by its id, never its email, and anything else is ignored.
- **`tests/Unit/ShopEventRelayTest.php`**: a signed webhook goes through Messenger, and nothing is sent without a URL or a secret.
- **`tests/Unit/ExtensionTest.php`**: the configuration reaches the relay, the listener is tagged, the message is routed to the transport.

Alone, from the plugin directory:

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

Inside a shop that installs it, from the shop directory:

```bash
vendor/bin/phpunit -c <path to the plugin>/phpunit.xml.dist
vendor/bin/ecs check --config <path to the plugin>/ecs.php
```

## Traps

- **`postPersist` fires inside the flush.** The listener only dispatches a message, it never calls the server. With a `sync://` transport, the HTTP call would run inside the request of the shop.
- **Symfony Webhook must be enabled** in the framework configuration. It is by default when `symfony/webhook` is installed, and its handler is what sends the message.
