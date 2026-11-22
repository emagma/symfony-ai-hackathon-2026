# MCP Sylius 2.x bundle: user guide

This bundle lets your MCP server talk to a shop that runs **Sylius 2.0, 2.1, 2.2 or 2.3**.

It brings no MCP tool of its own.
It tells `emagma/mcp-sylius-bundle` which Admin API routes a 2.x shop serves.
Every tool of that bundle then works against your shop.

It is for the person who configures the deployment.

## What changes in the chat

Nothing appears.
Two things depend on the minor version of the shop:

- `refund_payment` works on every 2.x version (the refund endpoint appeared in 2.0).
- The `priority` field of `upsert_zones` needs Sylius 2.2 or later.
  On 2.0 and 2.1, an item that gives it is refused.

## Set it up

There is no configuration key.
Two things are needed:

1. Register the bundle next to the Sylius bundle.
2. Declare the shop's version:

```
MCP_SYLIUS_VERSION=2.3
```

Accepted values: `2.0`, `2.1`, `2.2`, `2.3`; a patch may follow (`2.2.9`).

## Who may use it

Nobody in particular: the bundle declares no grant.
The grants are those of the tools of the Sylius bundle.

## Commands

None of its own.
`mcp:sylius-version:check` of the Sylius bundle compares `MCP_SYLIUS_VERSION` with the version the shop publishes.

## Limits

- A minor that is not in the list (a future 2.4) stops the server on the first request, with the supported lines in the message.
  It is added to the bundle once its routes are checked.
