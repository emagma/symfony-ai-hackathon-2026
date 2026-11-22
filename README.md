<h1 align="center">Emagma Agent Backend for Sylius</h1>

<p align="center">AI that helps run a Sylius shop day to day: from a chat, in the background, and on its own.<br>
Built with <a href="https://ai.symfony.com">Symfony AI</a> and the official PHP MCP SDK.<br>
A project of the <a href="https://symfony.com/hackathon/projects/emagma-agent-backend-for-sylius">Symfony AI &amp; MCP Hackathon 2026</a>.</p>

<p align="center"><a href="https://github.com/emagma/symfony-ai-hackathon-2026/actions/workflows/quality.yml"><img src="https://github.com/emagma/symfony-ai-hackathon-2026/actions/workflows/quality.yml/badge.svg" alt="Quality"></a></p>

> [!WARNING]
> **Proof of concept, not for production.** Built for the Symfony AI Hackathon, November 2026.
> See [Before you start](#before-you-start).
>
> **Hackathon jury**: start with [HACKATHON.md](HACKATHON.md), from the clone to every demo.

Emagma Agent Backend turns Sylius, the Symfony-based open-source e-commerce platform, into an
AI-operable back office, built with Symfony AI and the official PHP MCP SDK. Its tools cut the
round trips an agent makes to the shop's APIs, and a bundled plugin trims the API Platform payloads
to the data the agent needs, saving tokens. A Keycloak adapter plugs into the MCP authorization
flow, so each person's roles decide which tools they can see and call. When the client supports
elicitation, the server asks for missing details or confirmation before sensitive operations.
Third-party plugins expose their own tools through an extension point, and yours register as
Symfony services.

## Getting started

### Requirements

- **Docker Engine with Compose v2 or later**, and access to the Docker daemon (on Linux, your user
  in the `docker` group).
- **[Castor](https://castor.jolicode.com/getting-started/installation/)**, a standalone phar:
  `curl "https://castor.jolicode.com/install" | bash`.
- **`bash` and `openssl`**: `castor init` stops without them (they resolve the environment and
  generate its passwords).
- **`sudo`**, once, to add `127.0.0.1 keycloak` to `/etc/hosts` (see below).
- **An [Anthropic API key](https://console.anthropic.com/settings/keys)**: the chat can use other
  providers, but the server's own background work (long-running tasks, the fraud agent) uses
  Anthropic.
- **Free ports**: `castor init` warns about any port already taken.

| Port | Service |
|---|---|
| 80 | the shop |
| 8081 | the MCP server |
| 8090 | the chat |
| 3306 | MySQL |
| 8085 | Keycloak |
| 8025 | Mailpit (web UI) |
| 1025 | Mailpit (SMTP) |

### Up and running in about 5 minutes

1. Clone the repository:

   ```bash
   git clone https://github.com/emagma/symfony-ai-hackathon-2026.git
   cd symfony-ai-hackathon-2026
   ```

2. Put your Anthropic key in `.project.local.ini` (git-ignored, never committed):

   ```bash
   printf '[infrastructure.ai.anthropic]\napi_key = sk-ant-...\n' > .project.local.ini
   ```

   Replace `sk-ant-...` with your key. `castor init` keeps it and fills in the rest of the file.

3. Start the stack:

   ```bash
   castor init
   ```

It resolves the environment, builds the image and starts the containers, installs the three
applications' dependencies, boots Keycloak with the `sylius` realm and checks that its roles cover
the grants the MCP server declares, creates and seeds the shop (with the MCP service account),
migrates the server's own database, builds the shop's assets, and then **verifies** the whole
thing, including that the Anthropic key reaches the containers (not that the key is valid: the
first chat message tells). It prints every URL and credential when it is done. Migration
warnings and a Sylius telemetry note in its output are expected.

`castor init` offers to add `127.0.0.1 keycloak` to `/etc/hosts`: answer **yes**. The OIDC issuer
`http://keycloak:8085` has to resolve to the same place from your browser and from the containers;
without it the chat login and the remote connectors fail.

Forgot the key, or want another provider in the chat (OpenAI, Mistral, Qwen)? Add its section to
`.project.local.ini` (`[infrastructure.ai.openai]`, `.mistral`, `.qwen`, each with `api_key`), then
`castor init:env && castor docker:up`. A provider without a key is greyed out in the chat.
`castor init:verify` says whether the Anthropic key reaches the containers, without printing it.

### Options of `castor init`

| Option | Effect |
|---|---|
| `--skip-build` | do not build the image (use the one already built) |
| `--skip-db` | do not create or seed the databases |
| `--no-fixtures` | create the shop's schema and the service account, without the demo data |
| `--skip-assets` | do not build the shop's front-end assets |
| `--skip-hosts` | do not check or edit `/etc/hosts` |
| `--yes` | answer yes to every question: edits `/etc/hosts` and drops the shop database |

### Running it again

`castor init` is safe to re-run:

- **The shop database**: when it already holds data, `castor init` asks
  `The shop database already holds data. Drop it and re-seed?`. The default is **no**: the data is
  kept. `--yes` drops and re-seeds it without asking; `-n` (no interaction) keeps it.
- **The MCP server's database**: created if missing, then migrated. Never dropped.
- **The Keycloak realm**: re-imported only if its roles no longer cover the server's grants.

`castor shop:db` re-seeds the shop alone. The demo data is reproducible: the same catalogue,
stock, customers and orders at every load, with dates relative to the day of the load (only the
orders' tokens and the three promotions of Sylius' default suite vary).

### URLs

| What | URL | Log in |
|---|---|---|
| Chat | http://localhost:8090 | `admin` / `admin` |
| Shop | http://localhost/en_US/ | - |
| Back office | http://localhost/admin | `castor init:info` |
| MCP endpoint | http://localhost:8081/_mcp | a Keycloak bearer token |
| Keycloak | http://localhost:8085 | `admin` / `admin` (console) |
| Emails (Mailpit) | http://localhost:8025 | - |

The shop runs in Symfony **prod** without debug (set `app_env` / `app_debug` in `.project.ini`
`[apps.shop]` for dev); the MCP server and the chat run in dev.

## An MCP server platform, assembled from Symfony bundles

An LLM knows only what it read and can only talk. Give it tools that follow the MCP protocol and
it reads your systems, acts on them, and follows your rules. The platform is the server side of
that: a set of Symfony bundles you assemble into an MCP server, whatever the system behind it.

- **Tools per person**: the MCP endpoint is an OAuth protected resource. The caller's roles become
  grants, and `tools/list` only shows the tools those grants allow. A Keycloak adapter ships with it.
- **A human before every write**: through MCP elicitation, the server shows a preview of the change
  and waits for the human's confirmation. The model's own confirmation is then ignored.
- **Reversible writes**: each update goes to an audit trail with its actor, and one tool call
  undoes it.
- **What the business knows, served to the model**: guidelines, internal procedures and skills
  become MCP tools and resources. Some are Markdown files a merchant edits, some are code; any MCP
  client gets them.
- **Work beyond the conversation**: long-running tasks, background agents, screens inside the chat.
  See [Key features](#key-features).
- **One bundle per capability**: the system behind the server is a bundle too. A tool, a screen or
  an agent of yours is a tagged Symfony service.

## What it does for a Sylius shop

A merchant's day is made of small back-office actions: fix a price, translate a catalogue, check a
suspicious account, find the procedure for a damaged return. The Sylius bundles let an AI take them
on at three levels.

| Level | Who starts it | Example |
|---|---|---|
| MCP tools | the operator, in a chat | "Create a customer group VIP" |
| Long-running tasks | the operator, once | translate the catalogue into Italian |
| Background agents | the shop's events | flag a risky new account |

- **147 tools over the Admin API**: products, orders, customers, promotions, taxes, shipping and
  more. Batch upserts of up to 50 items, with one confirmation per batch.
- **A lean shop plugin**: it trims the API Platform payloads to what the agent needs and creates
  the service account. Nothing AI runs inside the shop.
- **One service account, many people**: the server calls the shop with one account. The person's
  identity stays in the server, where it decides the grants and signs the audit trail.
- **Sylius 2.0 to 2.3**: the server is told the shop's Sylius line and uses its Admin API routes.
- **Shop extensions**: a Sylius plugin can bring its own tools through an extension point; the
  server lists them only when the extension is turned on.

## Key features

### Long-running tasks: writes that do not rely on the model's good will

- **What**: for a job too long for a chat turn, the model writes a PHP script instead of making
  hundreds of tool calls. The script is the plan: fixed steps, the tools it may call, the parameters
  it uses. A model explains the script in plain language, and nothing runs before you approve it.
- **How it stays safe**: each step runs in a hardened PHP process, calls only the tools the script
  declares, and acts with your rights. The first error suspends the task. Each call is journaled.
- **Where the LLM still helps**: only where a step needs it, through `llm_transform`, which returns a
  typed answer from a model without tools.
- **Example**: `product_translate` prepares the translation of every untranslated product into a
  locale, with one model call per product.
- **Built on**: Symfony AI Platform, Messenger, Process, Notifier.
- **Try it**: Demo 2 - A long task in the background.

### Background agents

- **What**: agents that watch the shop and report. The shop sends a signed webhook for each event
  (ids only, never the data), and the event wakes the agent.
- **Example**: the fraud agent looks at new accounts. Rules in code settle the clear cases, and the
  model sees only the shape of an ambiguous address, never the address.
- **Built on**: Symfony AI Agent, Webhook, RemoteEvent, Messenger, Scheduler, Notifier.
- **Try it**: Demo 3 - An agent that works while you are away.

### Business guidelines

- **What**: who the business is and how it writes each kind of content. The server gives these
  guidelines to the model before it writes.
- **Built on**: the MCP server instructions, assembled from every bundle.
- **Try it**: Demo 1 - Guidelines the server hands to the model.

### Internal procedures

- **What**: the company's procedures, searched from the chat. Each answer quotes its source.
- **Built on**: Symfony AI Store (SQLite, full-text search; hybrid with an embeddings platform).
- **Try it**: Demo 4 - Ask the internal procedures.

### Screens in the conversation

- **What**: small forms shown inside the chat, such as the prices and stocks of a product's
  variants, or an address. They go through the same confirmation as any other write.
- **Built on**: MCP Apps of `symfony/mcp-bundle`, rendered with Twig.
- **Try it**: Demo 5 - Screens in the conversation.

### Skills served by the server

- **What**: Agent Skills the server hands to its clients, filtered by the caller's grants.
- **Example**: `morning-brief`.
- **Try it**: Demo 6 - Skills served by the server.

## How it fits together

Three applications in one stack. The MCP server is a separate application: it never runs inside
the shop and never reads the shop's database.

```text
Claude, ChatGPT, Claude Code ──┐
                               ├── JSON-RPC + bearer ──▶ apps/mcp-server :8081 ── HTTP + JWT ──▶ apps/shop :80
apps/chat :8090 ───────────────┘                          │
                                     Keycloak :8085 ◀─────┤ who may call which tool
                                     its own database ◀───┘ audit trail, tasks, agents
```

- **The shop** (`apps/shop`): a standard Sylius 2.3 with
  [`sylius-mcp-plugin`](packages/sylius-mcp-plugin/docs/user-guide.md), which trims the Admin
  API payloads to what an agent needs and creates the service account. Nothing AI runs in it.
- **The MCP server** (`apps/mcp-server`): an empty Symfony application that assembles the
  `packages/mcp-*-bundle`. It calls the shop's Admin API with one service account; the caller's
  identity stays in the server, where it decides the grants and signs the audit trail.
- **The chat** (`apps/chat`): a test client. It logs in with Keycloak, calls a model on its
  provider's API, and hands it the server's tools. Claude, ChatGPT or Claude Code connect the same
  way.
- **Keycloak**: the `sylius` realm. A caller's realm roles become grants, and the server only lists
  and runs the tools those grants allow.

The path of a tool call:

1. The client sends `tools/call` with a Keycloak bearer token. Without one, the server answers 401
   and points to the realm (RFC 9728).
2. The server checks the token and turns its roles into grants. A tool the grants do not allow is
   neither listed nor run.
3. A write shows a preview and waits for the human's confirmation (MCP elicitation).
4. The tool calls the shop's Admin API with the service account. Lists ask for lean payloads.
5. Every update goes to the audit trail with its actor and can be reverted with one tool call.

Work that outlives a request runs in workers of the server, each on its own Symfony Messenger
queue: the steps of long-running tasks (`mcp-tasks-worker`), the passes of background agents woken
by the shop's events and the outgoing emails (`mcp-agents-worker`), and a five-minute schedule as a
safety net (`mcp-scheduler`). The shop sends its events as signed webhooks (`shop-worker`).

## Before you start

- **A proof of concept**: run it on your computer, with the demo data it loads. Read
  [what guards the MCP endpoint](packages/mcp-auth-bundle/docs/developer-guide.md#before-you-expose-the-server)
  before you expose anything. The sandbox of the long-running tasks, for example, relies on PHP's
  own restrictions and has to be replaced by a dedicated task server first.
- **Your credits**: the chat and the server call a model with your key, and every call is billed to
  your account.
  - Pick a **Haiku** model in the chat's top bar: the whole tour then stays well under one dollar.
  - The chat shows the tokens of every model call.
  - A long-running task calls the model once per item: translating the catalogue means one call
    per product. Start with a few.
  - The fraud agent calls the model only for the new accounts its rules cannot settle.
    `castor docker:down` stops it.
  - Set a spending limit in the Anthropic console.

## Take the tour

> **Test chat - do not use in production.** The chat is a development tool for your own computer:
> its secret is a public placeholder, its Keycloak client is declared for development only, the
> server shows it personal data in clear in the dev environment, and it calls the models with your
> keys. See [apps/chat/README.md](apps/chat/README.md).

Eight demos, about five minutes each, made for **the local chat that `castor init` already
started**: open http://localhost:8090, log in as `admin` / `admin`, and pick **Anthropic** and
`claude-haiku-4-5-20251001` in the top bar (the chat preselects a Sonnet model, which costs more).
The chat shows what a hosted client hides: each model call with its tokens, each tool call, the
confirmation of each write.

You can also use your own Claude, or any MCP client: see
[Use it from Claude, ChatGPT or Claude Code](#use-it-from-claude-chatgpt-or-claude-code).

- **In the chat**: click the guide button on the start screen, or type
  `Guide me through the demos of the hackathon`. The model walks you through them one at a time.
- **On paper**: [HACKATHON.md](HACKATHON.md), what to type, what to look at, and the Symfony AI
  concepts behind each demo.
- **What to check**: the hackathon bundle's
  [user guide](packages/symfony-ai-hackathon-2026-bundle/docs/user-guide.md#check-each-demo), the
  expected result of each demo and what must not happen.

| # | Demo |
|---|---|
| 0 | A chat that drives the shop |
| 1 | Guidelines the server hands to the model |
| 2 | A long task in the background |
| 3 | An agent that works while you are away |
| 4 | Ask the internal procedures |
| 5 | Screens in the conversation |
| 6 | Skills served by the server |
| 7 | The same chat, another person |

Demo users (password = username):

| User | May |
|---|---|
| `admin` | do everything |
| `shop-reader` | read everything, change nothing |
| `catalog-manager` | read and write the catalogue only |
| `noaccess` | nothing (valid identity, no grant) |

`castor init:info` lists the others.

## Use it from Claude, ChatGPT or Claude Code

The local chat is the default path. The project's MCP server is a standard MCP server: any MCP
client that speaks OAuth connects the same way. The server answers 401, points to the Keycloak
realm, and the client logs in as a demo user.

### The server's parameters

| Parameter | Value |
|---|---|
| Transport | Streamable HTTP |
| URL | `http://localhost:8081/_mcp` |
| Authorization | OAuth 2.1, authorization code with PKCE (`S256`) |
| Discovery | the 401's `WWW-Authenticate` points to the protected resource metadata (RFC 9728) |
| Authorization server | Keycloak, realm `sylius`: `http://keycloak:8085/realms/sylius` |
| OAuth client, desktop and command line | `mcp-cli`, public, no secret; redirect on `http://localhost` or `http://127.0.0.1`, any port |
| OAuth client, Claude web and ChatGPT | `mcp-llm`, public, no secret; through the tunnel only |
| Users | the demo users (password = username): each user's roles decide the tools the client sees |

A client configured by file takes the same entry as this repository's `.mcp.json`:

```json
{
    "mcpServers": {
        "sylius-mcp": {
            "type": "http",
            "url": "http://localhost:8081/_mcp",
            "oauth": { "clientId": "mcp-cli" }
        }
    }
}
```

### Claude (web, desktop) and ChatGPT

Their connectors are called from the vendor's cloud, the desktop app's included, and cannot reach
`localhost`: `castor tunnel:up` opens two Cloudflare quick
tunnels, one to the MCP server and one to Keycloak's `sylius` realm, and prints the connector
settings.

1. Run `castor tunnel:up` and copy the MCP URL it prints (`https://<random>.trycloudflare.com/_mcp`).
2. **Claude**: Settings → Connectors → Add custom connector. Remote MCP server URL: the MCP URL;
   OAuth Client ID (advanced settings): `mcp-llm`; no client secret.
3. **ChatGPT**: Settings → Connectors → Create, in developer mode. MCP Server URL: the MCP URL;
   authentication OAuth; OAuth Client ID `mcp-llm`; no client secret.
4. Connect, log in with a demo user, and accept the consent screen.
5. Run `castor tunnel:down` when you are done.

> [!CAUTION]
> **While the tunnel is open, the demo users are public.** Anyone who has the tunnel's URL can log
> in as a demo user (password = username) and act on your local shop with that user's grants. Only
> the `sylius` realm goes through the tunnel; the Keycloak admin console stays local. Close the
> tunnel with `castor tunnel:down` as soon as you are done.

### Claude Code

- **In this repository**: `.mcp.json` declares the server (`sylius-mcp`, OAuth client `mcp-cli`).
  Start `claude`, accept the server, then `/mcp` → `sylius-mcp` → Authenticate, and log in with a
  demo user.
- **Elsewhere**:

  ```bash
  claude mcp add --transport http --client-id mcp-cli sylius-mcp http://localhost:8081/_mcp
  ```

  No tunnel is needed: the login goes to `http://keycloak:8085`, which the `/etc/hosts` entry maps
  to your machine. Through the tunnel, `castor tunnel:up` prints the same command with the
  tunnel's URL.

### What differs from the local chat

- **Tokens**: no badges of the model calls (demo 0).
- **Writes**: no **Writes** switch; your client's own approval of the tool call stands in for the dialog.
- **Skills**: read with `list_skills` and `get_skill`, no Skills panel (demo 6).
- **Users**: to change user (demo 7), disconnect the connector and authenticate again as `shop-reader`.
- **Screens**: they show only in a client that renders MCP Apps (demo 5).
- **The tour**: ask `Guide me through the demos of the hackathon` there too, `list_demos` and `get_demo` are served to any client.

### Who pays for what

| Client | The conversation's model | Tasks and the fraud agent |
|---|---|---|
| The local chat | your provider keys | your Anthropic key, set on the server |
| Claude web, ChatGPT | your subscription | your Anthropic key, set on the server |
| Claude Code | your Claude Code plan | your Anthropic key, set on the server |

## Everyday

```bash
castor init:info           # URLs and credentials
castor init:verify         # the shop answers, /_mcp is gated, grants match the realm, the Anthropic key is set
castor shop:console …      # the shop's Symfony console (mcp:console, chat:console likewise)
castor shop:db             # re-seed the shop
castor mcp:permissions     # what every tool requires, what the realm hands out
castor tunnel:up           # expose the MCP server to Claude web / ChatGPT (Cloudflare quick tunnels)
castor quality:all         # ECS + PHPStan + PHPUnit of every app and package - the same gates as CI
castor docker:down         # stop everything (the data is kept)
docker compose --env-file docker/.env down -v   # remove the containers and the volumes of this clone
```

`castor list` gives every command.

## What is in the repository

```text
apps/shop          Sylius 2.3 with the plugin and the official CMS plugin     :80
apps/mcp-server    the MCP server, an assembly of the packages/mcp-*-bundle   :8081/_mcp
apps/chat          a multi-LLM test chat, MCP client of the server            :8090
packages/          the shop plugins and the server's bundles
knowledge/         the internal procedures (Markdown)
skills/            the skills served to the clients
```

## Documentation

- [HACKATHON.md](HACKATHON.md): the guided tour, demo by demo, with the Symfony AI concepts each one exercises
- [The hackathon bundle](packages/symfony-ai-hackathon-2026-bundle/docs/user-guide.md): the tour in the chat, and what to check after each demo ([developer guide](packages/symfony-ai-hackathon-2026-bundle/docs/developer-guide.md))
- [apps/chat/README.md](apps/chat/README.md): the local test chat: login, tokens, confirmations, screens, skills
- [sylius-mcp-plugin](packages/sylius-mcp-plugin/docs/user-guide.md): the shop plugin, from a shop integrator's point of view ([developer guide](packages/sylius-mcp-plugin/docs/developer-guide.md))
- [mcp-auth-bundle](packages/mcp-auth-bundle/docs/developer-guide.md): what guards `/_mcp`, and what to review before exposing the server
- [mcp-core-bundle](packages/mcp-core-bundle/docs/user-guide.md): grants, write confirmation, redaction, privacy ([developer guide](packages/mcp-core-bundle/docs/developer-guide.md))
- [mcp-auth-keycloak-bundle](packages/mcp-auth-keycloak-bundle/docs/developer-guide.md): the Keycloak realm: roles, groups, clients
- [mcp-sylius-bundle](packages/mcp-sylius-bundle/docs/user-guide.md): the Sylius tools, and the Admin API routes of each Sylius line ([developer guide](packages/mcp-sylius-bundle/docs/developer-guide.md))
- `packages/*/docs/`: a user guide and a developer guide for every bundle and plugin

## License and credits

Emagma Agent Backend for Sylius is a proof of concept released under the [MIT license](LICENSE),
built for the Symfony AI Hackathon in November 2026 by [Emagma](https://www.emagma.fr): Kévin
Gonella, Emilie Bonfill and Loïc Caillieux. It stands on [Sylius](https://sylius.com),
[Symfony AI](https://ai.symfony.com) and the [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk).
