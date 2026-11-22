# MCP Screens Bundle: user guide

The screens bundle lets an MCP server show a small screen inside the conversation (an MCP App).

A screen is a table or a form on one precise thing.
The user reads it and, when allowed, edits and saves it.

The bundle brings the mechanism only.
The screens themselves come from other bundles (for a Sylius shop: the prices of a product's variants, an order's address).

- **Screen**: a named, focused operation shown in the conversation.
- **App**: the one generic page (`ui://screens`) that displays every screen.

## What you can do

### Open a screen

- **MCP Tool**: `open_screen`, the only one of the three the model sees.
- **Arguments**: `screen` (its name) and `params` (an object saying what it is about, empty by default).
- **What to type**: "Show me the prices and stocks of the variants of this T-shirt so that I can correct them."
- **What comes back**: the screen, displayed in the conversation.
  The model also receives what the screen shows, so it can talk about it.

### Work in the screen

The buttons of a screen call the server without going through the model.

| Action in the screen | MCP Tool called | Effect |
|---|---|---|
| Edit, Cancel, a selector | `screen_render` | Shows the screen again, as a form or a display |
| Save | `screen_submit` | Checks the values, writes, shows the screen again |

- **Read only**: a user who may see a screen but not save it gets no edit button.
- **Invalid values**: the form comes back with what was typed and a message under each wrong field.
- **Saved**: the screen goes back to its display, read again from the backend, with a confirmation message.
- **Confirmation**: a save goes through the server's ordinary write path, so the user confirms it as any other write.

### When a screen is not shown

`open_screen` answers `ok: false` with a `reason`, a `message` and the screens the caller may open.

| `reason` | Meaning |
|---|---|
| `unknown_screen` | No screen has that name |
| `forbidden` | The caller lacks the grants of the screen |
| `unavailable` | The screen cannot show this (unknown item, missing param) |

## Setting it up

- **Nothing to configure**: no key, no environment variable.
- **Register** the bundle and a bundle that brings screens.
- **Twig** must be installed: the screens are Twig templates rendered by the server.
- **The client** must be an MCP Apps host: it displays the `ui://screens` document and relays the calls of the screen.

## Who may use it

| What | Grant |
|---|---|
| `open_screen`, `screen_render`, `screen_submit` | `mcp:core:screen:read` |
| Seeing one screen | the read grants that screen declares |
| Saving one screen | the write grants that screen declares |

- `mcp:core:screen:read` only opens the door.
- Each screen is checked again, on every call, against its own grants.
- Nobody sees or changes more through a screen than through the MCP Tools.

## Commands

None.

## Limits and things to know

- **A screen is a focused operation**, not a back office: one product's variants, one address.
- **Hosts**: only a chat that displays MCP Apps works; check yours before relying on it.
- **The model does not always see the markup**: a client may strip the `html` of the result and hide `screen_render` and `screen_submit` from the model.
- **A save needs a client that can ask the human**: without it, a screen that writes answers that nothing was saved.
- **Time**: the wait for a confirmation is bounded by the server's execution time limit.
- **A screen does not show**: check `mcp:core:screen:read` first (without it `open_screen` is not even listed), then the grants of the screen (`reason: forbidden`).
