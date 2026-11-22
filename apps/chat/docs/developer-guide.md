# Chat: developer guide

> **A test application.**
> The chat exists to test the MCP server.
> It is not a base to develop a product on, nor an application to deploy: see the [user guide](user-guide.md) for why.

## At a glance

- **Composer name**: `emagma/sylius-mcp-chat`, its own composer root (`apps/chat/composer.json`, its own `vendor/`).
- **Namespace**: `Emagma\SyliusAssistantTestChat`.
- **Built on**: Symfony 7.4, Symfony AI (the platforms and the agent), `symfony/mcp-bundle` ^0.15 as an MCP **client**, `league/oauth2-client`.
- **Port**: 8090, service `chat` of `compose.chat.yaml`.
- **No database**: conversations and skills are files in `var/`.

## Topology

```text
browser ──► chat :8090 ──► provider API (OpenAI, Anthropic, Mistral, DashScope)
              │
              ├──► mcp-server /_mcp   (MCP client: symfony/mcp-bundle ^0.15)
              └──► keycloak :8085     (OAuth 2.1 + PKCE, client mcp-testchat)
```

- **A separate composer root**: the server and the chat both use `symfony/mcp-bundle` ^0.15, one as a server, the other as a client.
  The chat keeps its own `composer.json` and `vendor/`: that is the honest topology of a remote MCP client, and each side upgrades at its own pace.
- **Login**: the chat discovers the authorization server the way a real MCP client does.
  First the server's RFC 9728 metadata (`/.well-known/oauth-protected-resource`), then the realm's OIDC discovery.
  Authorization code with PKCE S256, public client `mcp-testchat`.
- **Identity**: the token carries the roles of the logged-in user, so `/_mcp` authorizes and audits that user, exactly as for a hosted client.
  The access token lives 300 s; it is renewed at the start of a turn when less than 240 s remain.
- **Tool names**: the MCP client prefixes each tool with the server's name (`shop`): the model sees `shop_search_products`.

## How it is built

| Directory | Role |
|---|---|
| `src/Auth/` | Discovery of the authorization server, OAuth client, login listener, token in session |
| `src/Catalog/` | Providers, the models they list, their prices when a price source is given |
| `src/Controller/` | The page, the JSON API, the stream of a turn |
| `src/Conversation/` | A conversation, its model calls, the files that store it |
| `src/Turn/` | One turn: the agent, the tools given to it, the metering |
| `src/Elicitation/` | Write confirmations between the turn and the browser |
| `src/Mcp/` | The HTTP client to `/_mcp`, the tools of a screen, the filtered toolset |
| `src/Skill/` | The skills the server offers, those the user installed |
| `src/Tracing/` | OpenTelemetry traces, a no-op here (no endpoint) |
| `src/Command/` | `testchat:catalog`, `testchat:mcp:call` |

- **Front end**: Twig (`templates/chat.html.twig`) and vanilla JavaScript (`public/app.js`, `public/format.js`), no build step.
- **Style**: [Basecoat](https://basecoatui.com) 1.0.2, the components and tokens of shadcn/ui in plain HTML and CSS, copied into `public/vendor/` (no CDN at runtime).
  `public/app.css` only lays out the page, on the shadcn tokens.
- **Answers**: HTML-escaped before any Markdown formatting.

## A turn

`POST /api/conversations/{id}/turns` answers with server-sent events while the turn runs:

| Event | Content |
|---|---|
| `llm_call` | Model, tokens by kind, cost of the call, totals |
| `tool_call` | Start and end of each MCP tool call |
| `elicitation` | A write confirmation to show, with its deadline |
| `elicitation_resolved` | `manual_accept`, `manual_decline`, `manual_cancel`, `auto_accept` or `preset_accept` |
| `assistant`, `error`, `done` | The answer, the failure, the totals |

- **`llm_call` in detail**: input, cached, cache-write and output tokens; the cost of the call (null without a price); the totals of the turn and of the conversation.
- **No streaming of the model's answer**: Symfony AI reports token usage only without streaming.
  The live view comes from the events, sent at each step.
- **Metering**: `Turn\MeteredPlatform` decorates the four platforms and counts each call of the tool loop when it ends.
  The agent's final aggregate is not added on top: it would count everything twice.
- **System prompt**: `prompts/system.md`, in English; the model answers in the user's language.
  It is set once, when the conversation is created.
  On the first turn, `Conversation\ServerInstructions` appends the `instructions` the MCP server declares at `initialize`, once for the whole conversation.
- **Tools given to the model**: every tool the user is granted, or only those whose name starts with a prefix of `chat.tools.allow` (`config/packages/mcp.yaml`).

## Write confirmations

The server sends an MCP elicitation before every write; `Elicitation\UiElicitationCallback` turns it into the `elicitation` event.
Accepting sends `confirm: true`: for the server, an acceptance without content is a refusal.

- **How the answer gets back**: the turn's request waits inside the elicitation callback.
  The browser's answer arrives on another request (`POST /api/elicitations/{id}`), served by another FrankenPHP thread (`num_threads 8`).
  They meet in the cache pool `chat.elicitations` (filesystem).
- **No session lock**: the session is saved before streaming, and the answer route does not open it.
- **The proof**: the confirmation id, 128 random bits.
- **Auto-accept**: with the Writes switch on **Auto-accepted**, the turn is sent with `auto_accept`, and the chat answers every elicitation with an acceptance at once (outcome `preset_accept`).

The chain of timeouts, in this strict order:

| Step | Delay |
|---|---|
| Dialog (automatic acceptance) | 30 s |
| MCP request (`mcp.clients.sylius.request_timeout`) | 50 s |
| MCP HTTP client (`Mcp\McpHttpClient`) | 55 s idle, 58 s in total |
| `max_execution_time` of the MCP server (`docker/php/conf.d/app.ini`) | 60 s |
| `max_execution_time` of the chat | none (`apps/chat/docker/php.ini`) |

## Screens (MCP Apps)

The chat is an MCP Apps host: a tool linked to a `ui://` resource (`open_screen`) shows a screen in the conversation.

- **What the model sees**: `open_screen` only.
  The tools reserved to the screen (`visibility: [app]`, here `screen_render` and `screen_submit`) are removed from its list (`Mcp\FilteredToolset`).
  The screen's markup is removed from the result it reads (`Turn\TurnToolbox`): it gets the data shown, not the HTML.
- **The screen**: the App's document is read from the MCP server with the user's token (`GET /api/apps/resource`).
  It is placed in an isolated iframe (`sandbox="allow-scripts allow-forms"`, without the page's origin).
  It talks to the page in JSON-RPC over `postMessage`: handshake, tool result, height, links (only http(s) links open).
- **The screen's calls**: relayed by `POST /api/apps/call`, which accepts only the tools of the same App (403 otherwise).
  A write goes through the same confirmation as a turn, on the same kind of stream.
- **Replay**: the screen is saved with the turn's activity.

## Data

- **Conversations**: one pair of files per conversation in `var/conversations/` (git-ignored).
  `<id>.ser` holds the history, `<id>.json` the summary the list reads, so the list never unserializes a history.
  A history whose classes changed since it was saved is skipped by the list and refused when opened.
  Deleting answers 409 while a turn is running; another user's conversation answers 404.
- **Skills**: `var/skills/`, one file per user: the name, description and version of each installed skill.
  The instructions stay on the server and are read with `get_skill`.
- **Models**: the lists of the providers are cached one hour.
  Mistral declares the capabilities of its models; OpenAI and Qwen are filtered by name; Anthropic lists chat models only.
  A model unknown to Symfony AI is accepted anyway (`Platform\FallbackModelCatalog`).
- **Qwen**: no Symfony AI bridge.
  It goes through the generic platform, on DashScope's OpenAI-compatible endpoint (`TESTCHAT_QWEN_BASE_URL`).

## Prices and traces

This delivery gives the chat neither a price source nor a trace endpoint: their code stays, inactive.

- **Prices**: no provider returns a price, only tokens.
  `Catalog\PriceBook` reads prices only when `TESTCHAT_LANGFUSE_URL` and `LANGFUSE_PROJECT_PUBLIC_KEY` are both set (`PriceBook::isConfigured()`); compose passes neither.
  So the cost of a call is null, and the page shows tokens only.
- **Token kinds**: `cache_reporting` in `config/packages/chat.yaml` says how a provider counts its cache.
  OpenAI, Mistral and Qwen count it inside the input, and the chat subtracts it; Anthropic reports it apart.
- **Traces**: `Tracing\TurnTracer` is a no-op without `OTEL_EXPORTER_OTLP_ENDPOINT`.

## Configuration

| File or variable | What it sets |
|---|---|
| `config/packages/chat.yaml` | The providers: platform, key, `default_model`, `cache_reporting` |
| `config/packages/mcp.yaml` | The MCP client, its timeouts, `chat.tools.allow` |
| `TESTCHAT_<PROVIDER>_API_KEY` | The provider keys, from `.project.local.ini` through `docker/.env` |
| `TESTCHAT_MCP_URL`, `TESTCHAT_PUBLIC_URL` | The MCP server, the chat's own URL (OAuth callback) |

The keys never go in `apps/chat/.env`: it is committed, and compose's variables win over it.

## Tests and quality

```bash
castor chat:quality    # in the chat container
```

- **PHPStan** (level max) and **PHPUnit** (`tests/Unit/`, one folder per directory of `src/`).
- **JavaScript**: `node --test 'tests/js/*.test.js'` (the formatting of tokens and costs, `public/format.js`) and `node --check public/app.js`.
- **Same as CI**: `bin/ci/chat.sh`, part of `castor quality:all`.
