# MCP Server: user guide

## What it does

The MCP server lets an AI assistant run a Sylius shop's back office, with the rights of the person who uses it.

Claude, ChatGPT, Claude Code or the stack's test chat connect to it at `/_mcp`.
It hands them the tools their user is granted: search the catalog, update prices, ship an order, translate products in the background.
Each tool calls the shop's Admin API.

The server is a separate application: it never runs inside the shop and never reads the shop's database.
Its features come from the `packages/mcp-*-bundle` it assembles; this application only wires them for this installation.

This guide is for whoever runs the server and connects clients to it.
How it is assembled: the [developer guide](developer-guide.md).

## What happens on a tool call

1. The client sends `tools/call` with a Keycloak bearer token.
   Without one, the server answers 401 and points to the realm (RFC 9728).
2. The server checks the token and turns the user's realm roles into grants.
   A tool the grants do not allow is neither listed nor run.
3. A write shows a preview and waits for the human's confirmation (MCP elicitation).
4. The tool calls the shop's Admin API with **one service account**.
   The caller's identity stays in the server.
5. Every update is written to the audit with its actor, and can be reverted with one tool call.

## Connecting a client

| Client | How |
|---|---|
| Claude Code | The repository's `.mcp.json` (Keycloak client `mcp-cli`), then `/mcp` to log in |
| Claude web, ChatGPT | `castor tunnel:up` publishes the server and the realm, then add the server's public URL as a connector (client `mcp-llm`) |
| The test chat | Already wired: http://localhost:8090 (client `mcp-testchat`) |

- **Locally**: http://localhost:8081/_mcp.
- **Users**: the realm's demo users, password = username.
  `admin` holds every grant; the others hold the grants of a profile (`catalog-manager`, `sales-reader`, `auditor`, `noaccess`…).
  The roles and groups: the [Keycloak developer guide](../../../packages/mcp-auth-keycloak-bundle/docs/developer-guide.md).

## What it offers

Each family of tools is documented with its bundle:

| Family | Bundle guide |
|---|---|
| The Sylius tools: catalog, orders, customers, promotions, shipping, taxes, configuration | [mcp-sylius-bundle](../../../packages/mcp-sylius-bundle/docs/user-guide.md) |
| Audit, revert, erasure of personal data | [mcp-audit-bundle](../../../packages/mcp-audit-bundle/docs/user-guide.md) |
| Background tasks written on demand | [mcp-tasks-bundle](../../../packages/mcp-tasks-bundle/docs/user-guide.md) |
| Background agents woken by the shop's events | [mcp-agents-bundle](../../../packages/mcp-agents-bundle/docs/user-guide.md) |
| The business's guidelines, procedures and skills | [guidelines](../../../packages/mcp-guidelines-bundle/docs/user-guide.md), [knowledge](../../../packages/mcp-knowledge-bundle/docs/user-guide.md), [skills](../../../packages/mcp-skills-bundle/docs/user-guide.md) |
| Screens shown in the conversation | [mcp-screens-bundle](../../../packages/mcp-screens-bundle/docs/user-guide.md) |

The list of grants every tool declares, for the realm: `castor mcp:permissions`.

## Setting it up

The server reads the shop and its identity provider from the environment.
In this stack, `.project.ini` holds the values and `castor init:env` writes them to `docker/.env`.

| Variable | What it sets |
|---|---|
| `SYLIUS_API_URL` | The shop's Admin API |
| `MCP_SERVICE_ACCOUNT_EMAIL`, `MCP_SERVICE_ACCOUNT_PASSWORD` | The Sylius admin the server calls the shop as (`ROLE_API_ACCESS`) |
| `MCP_SYLIUS_VERSION` | The shop's Sylius line, `2.0` to `2.3` (2.3 by default) |
| `MCP_SHOP_EXTENSIONS` | The shop extensions the server may use, comma-separated (none by default) |
| `MCP_DEFAULT_LOCALE` | The locale of translated fields when a call names none |
| `MCP_AUTH_ISSUER_URL`, `MCP_AUTH_USERINFO_URL` | The Keycloak realm, as clients reach it and as the server checks tokens |
| `DATABASE_URL` | The server's own database: audit, tasks, agents, the shop's events |
| `MCP_AI_ANTHROPIC_API_KEY` | The server's own model calls (tasks, agents) |
| `MCP_EVENT_WEBHOOK_SECRET` | The secret the shop signs its webhooks with |
| `MCP_AGENT_ALERT_EMAIL`, `MAILER_DSN` | Where the agents' alerts go |

- **An unsupported `MCP_SYLIUS_VERSION` or an unknown extension stops the server.**
- **Check them against the shop**: `castor mcp:sylius-version` and `castor mcp:shop-extensions` (both in `castor init:verify`).
- **After a change**: `castor init:env`, then `castor docker:up` to recreate the containers.

## What runs

| Service | Role |
|---|---|
| `mcp-server` | `/_mcp`, the webhooks of the shop (`/webhook/sylius`), the consent of background tasks |
| `mcp-tasks-worker` | The steps of background tasks |
| `mcp-agents-worker` | The passes of background agents, the outgoing emails |
| `mcp-scheduler` | The agents' safety net, every few minutes |

Nothing reaches the mailbox while `mcp-agents-worker` is down.

## Commands

```bash
castor mcp:console <cmd>     # the Symfony console of the server
castor mcp:db                # create the server's database if missing, migrate it
castor mcp:permissions       # the grants every tool declares
castor mcp:sylius-version    # MCP_SYLIUS_VERSION against the shop
castor mcp:shop-extensions   # MCP_SHOP_EXTENSIONS against the shop
castor mcp:bench             # the "autonomous agent" scenario against the shop
castor mcp:qa                # the QA scenarios against a throwaway shop
```

## Before exposing it

The server writes to a real shop with an admin account.
Before it leaves your machine, read [what guards `/_mcp`](../../../packages/mcp-auth-bundle/docs/developer-guide.md): the token, the grants, the confirmation of writes, the data a client sees.
