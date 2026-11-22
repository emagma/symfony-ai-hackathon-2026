# Chat - the local test chat

> **Test chat - do not use in production.** It is a development tool, made to run on your own computer:
>
> - its `APP_SECRET` is a public placeholder (`apps/chat/.env`);
> - its Keycloak client, `mcp-testchat`, is declared for development only;
> - in the `dev` environment, the MCP server turns off the protection of personal data for this client: the model sees names, emails and addresses in clear;
> - it calls the models with the provider keys of whoever runs it.

`apps/chat` drives the shop **through the MCP server** (`/_mcp` of `apps/mcp-server`), the way
Claude or ChatGPT would, with a model called directly on its provider's API: OpenAI, Anthropic,
Mistral or Qwen. Since the chat calls the model itself, it shows what a hosted client hides: the
exact model and the tokens of every model call. It is the tool to compare models, user profiles
and versions of the server.

What it measures: *this agent, with this model, through the MCP server*. Not the cost of a
conversation in a hosted client, which adds its own system prompt and its own rules for using the
tools.

## Start

1. Put the provider keys in `.project.local.ini`, one section per provider:

   ```ini
   [infrastructure.ai.anthropic]
   api_key = "sk-ant-..."
   ```

   Sections: `openai`, `anthropic`, `mistral`, `qwen`. A provider without a key is greyed out in
   the chat.
2. Apply them and start the chat:

   ```bash
   castor init:env       # writes the keys to docker/.env
   castor docker:up      # or: castor chat:up, the chat alone
   castor chat:info      # its URL and the providers whose key is set
   ```

3. Open http://localhost:8090. The page sends you to Keycloak: log in with a demo user
   (password = username, see the
   [Keycloak developer guide](../../packages/mcp-auth-keycloak-bundle/docs/developer-guide.md#demo-users-stack-specific)).

- **Where the keys never go**: `apps/chat/.env`. That file is committed, and the keys would be
  ignored there anyway: compose sets the variables (`TESTCHAT_<PROVIDER>_API_KEY`), and a real
  environment variable wins over a `.env` file. `castor chat:info` warns when it finds one there.
- **Already running**: `compose.chat.yaml` is part of the default stack, so `castor init` installs
  and starts the chat.

### Models

- **Listed live**: the models each provider's API lists at the moment (cached one hour), kept to
  those that can hold a conversation with tools. Mistral declares it (`capabilities`); OpenAI and
  Qwen are filtered by name (embeddings, speech, image, realtime… left out); Anthropic lists chat
  models only.
- **Unknown to Symfony AI**: accepted anyway (`Platform\FallbackModelCatalog`).
- **Default**: `default_model` of each provider in `config/packages/chat.yaml` is preselected
  until the browser remembers another choice. With a price source, the priced models come first.
- **Qwen**: no Symfony AI bridge; it goes through the generic platform, on DashScope's
  OpenAI-compatible endpoint (international region by default, `TESTCHAT_QWEN_BASE_URL`).

### Diagnostics without a model

The same client stack, from the command line:

```bash
castor chat:console testchat:catalog              # models listed and known prices, per provider
castor chat:console testchat:catalog mistral      # every model of one provider and its price
castor chat:console testchat:mcp:call search_products '{"itemsPerPage":2}' --user=catalog-manager
castor chat:console testchat:mcp:call upsert_customer_groups '{"items":[{"code":"x","name":"X"}]}' --answer=decline
```

`testchat:mcp:call` logs in as a demo user with the `dev-cli` client (password grant) and answers a
write confirmation with `--answer` (`accept` or `decline`, `decline` by default).

## Interface

- **Stack**: Twig and vanilla JavaScript, no build step.
- **Style**: [Basecoat](https://basecoatui.com) 1.0.2, the components and tokens of shadcn/ui in
  plain HTML and CSS, copied into `public/vendor/` (no CDN at runtime). `public/app.css` only lays
  out the page, on the shadcn tokens (`--background`, `--muted`, `--border`…).
- **Theme**: the button at the top right toggles the dark theme (class `html.dark`).
- **Answers**: rendered as a small subset of Markdown, HTML-escaped before any formatting.
- **Top bar**: Provider and Model pickers, the **Writes** switch, the tokens of the conversation,
  the logged-in user and **Switch user**.

## Conversations

- **The list**: the left column lists the conversations of the logged-in user, latest first,
  grouped by day (Today, Yesterday, Last 7 days, Older), with the title (the first question), the
  last model used and, with a price source, the cost.
- **Reopening**: a click reopens a conversation without reloading the page, with its messages
  **and its activity** (model calls, tool calls, confirmations) replayed as badges. The URL carries
  the id (`#<uuid>`), so each browser tab can keep one open.
- **Storage**: one pair of files per conversation in `apps/chat/var/conversations/` (git-ignored):
  `<id>.ser` for the history, `<id>.json` for the summary the list reads, so the list never
  unserializes a history.
- **Older versions**: a conversation saved by an earlier version whose classes changed is skipped
  by the list and refused when opened; the page keeps working.
- **Deleting**: final. It answers 409 while a turn is running.
- **Privacy between users**: nobody sees or deletes another user's conversation (404).

## Topology

```text
browser ──► chat :8090 ──► provider API (OpenAI, Anthropic, Mistral, DashScope)
              │
              ├──► mcp-server /_mcp   (MCP client: symfony/mcp-bundle ^0.15)
              └──► keycloak :8085     (OAuth 2.1 + PKCE, client mcp-testchat)
```

- **A separate composer root**: the server and the chat both use `symfony/mcp-bundle` ^0.15, one as
  a server, the other as a client (with Symfony AI). The chat still keeps its own
  `apps/chat/composer.json` and `vendor/`: that is the honest topology of a remote MCP client, and
  each side upgrades at its own pace.
- **Login**: the chat discovers the authorization server the way a real MCP client does: the
  server's RFC 9728 metadata (`/.well-known/oauth-protected-resource`), then the realm's OIDC
  discovery. Authorization code with PKCE S256, public client `mcp-testchat`. The token carries the
  roles of the logged-in user: `/_mcp` authorizes and audits that user, exactly as for a hosted
  client. The access token lives 300 s; it is renewed at the start of a turn when less than 240 s
  remain.
- **Tool names**: the MCP client prefixes each tool with the server's name (`shop`): the model sees
  `shop_search_products`.

## A turn

`POST /api/conversations/{id}/turns` answers with server-sent events while the turn runs:

| Event | Content |
|---|---|
| `llm_call` | Model, tokens by kind, cost of the call, totals |
| `tool_call` | Start and end of each MCP tool call |
| `elicitation` | A write confirmation to show, with its deadline |
| `elicitation_resolved` | How it ended: `manual_accept`, `manual_decline`, `manual_cancel`, `auto_accept` or `preset_accept` |
| `assistant`, `error`, `done` | The answer, the failure, the totals |

- **`llm_call` in detail**: input, cached, cache-write and output tokens; the cost of the call
  (null without a price); the totals of the turn and of the conversation.
- **No streaming of the model's answer**: Symfony AI reports token usage only without streaming.
  The live view comes from the events, sent at each step.
- **Metering**: `MeteredPlatform` decorates the four platforms and counts each call of the tool
  loop when it ends; the agent's final aggregate is not added on top (it would count everything
  twice).
- **System prompt**: `apps/chat/prompts/system.md`, in English; the model answers in the user's
  language, English when unsure. It is set once, when the conversation is created. On the first
  turn, `ServerInstructions` appends the `instructions` the MCP server declares at `initialize`
  (the conventions every tool shares), once for the whole conversation.
- **Changing model mid-conversation**: allowed; each call is counted with its own model.

## Write confirmations

The server asks for a human confirmation (MCP elicitation) before every write. The chat shows it in
a dialog, **Confirm the write?**, with a countdown and three buttons: **Accept**, **Decline**,
**Cancel**. Without an answer after **30 s**, it is **accepted automatically**. Accepting sends
`confirm: true`: for the server, an acceptance without content is a refusal.

- **How the answer gets back**: the turn's request waits inside the elicitation callback; the
  browser's answer arrives on another request (`POST /api/elicitations/{id}`), served by another
  FrankenPHP thread (`num_threads 8`). They meet in a filesystem cache pool. The session is saved
  before streaming and the answer route does not open it: no session lock blocks. The confirmation
  id, 128 random bits, is the proof.
- **The Writes switch**: in the top bar, remembered by the browser. Set to **Auto-accepted**, the
  turn is sent with `auto_accept` and the chat answers every confirmation request with an
  acceptance at once, without the dialog. The badge and the trace (`ui.elicitation`, outcome
  `preset_accept`) say so. Handy to chain write tests; switch it back to **Confirm** before testing
  the confirmation flow itself.

The chain of timeouts, in this strict order:

| Step | Delay |
|---|---|
| Dialog (automatic acceptance) | 30 s |
| MCP request (`mcp.clients.sylius.request_timeout`) | 50 s |
| MCP HTTP client (`McpHttpClient`) | 55 s idle, 58 s in total |
| `max_execution_time` of the MCP server (`docker/php/conf.d/app.ini`) | 60 s |
| `max_execution_time` of the chat | none (`apps/chat/docker/php.ini`) |

## Screens (MCP Apps)

The chat is an MCP Apps host: when the model calls an MCP Tool linked to a `ui://` resource
(`open_screen`), the screen shows in the conversation.

- **What the model sees**: `open_screen` only. The MCP Tools reserved to the screen
  (`visibility: [app]`, here `screen_render` and `screen_submit`) are removed from its list
  (`FilteredToolset`), and the screen's markup is removed from the result it reads
  (`TurnToolbox`): it gets the data shown, not the HTML.
- **The screen**: the App's document is read from the MCP server with the user's token
  (`GET /api/apps/resource`) and placed in an isolated iframe
  (`sandbox="allow-scripts allow-forms"`, without the page's origin). It talks to the page in
  JSON-RPC over `postMessage`: handshake, tool result, height, links (only http(s) links open).
- **The screen's calls**: relayed by `POST /api/apps/call`, which accepts only the MCP Tools of the
  same App (403 otherwise). A write goes through the same confirmation dialog as a turn, on the
  same kind of stream.
- **Replay**: the screen is saved with the turn's activity; a reopened conversation shows it again,
  and its buttons call the server again.

## Skills

The **Skills** panel lists the skills the MCP server offers the logged-in user (`list_skills`,
filtered by their grants) and those they installed here. It is hidden when there are none.

- **Install**: the chat keeps the skill's name, description and version (`var/skills/`, one file per
  user). The instructions stay on the server.
- **At each turn**: the installed skills are named to the model in the system prompt, with their
  description. When the request matches, the model reads the instructions with `get_skill`, then
  follows them with the MCP Tools.
- **Update**: a served version different from the installed one is flagged in the panel
  (`v1 installed · v2 available`); the instructions read are always the server's.
- **Remove**: the skill is no longer named to the model, which then offers to install it rather
  than apply it. A skill the server no longer offers can only be removed.

## Tokens and cost

### Tokens

Always shown, in millions of tokens with four decimals (`MT`; a step of 0.0001 MT is 100 tokens,
rounded to the nearest):

- **Under each model call**:
  `claude-haiku-4-5 · in 0.0123 MT · out 0.0003 MT · cache read 0.0098 MT · cache write 0.0000 MT`.
  `in` is the input **without** the cache: the four parts add up.
- **In the top bar**: the total of the conversation, in the same format.
- **Where**: `public/format.js`, loaded before `app.js` and tested on its own
  (`node --test 'tests/js/*.test.js'`, from `apps/chat`).

### Cost

A cost is shown **only when a price source is configured**: `TESTCHAT_LANGFUSE_URL` and
`LANGFUSE_PROJECT_PUBLIC_KEY` both set (`PriceBook::isConfigured()`).

- **Without a price source**: no price is mentioned anywhere. No cost under the calls, no
  **Cost** card, no **Model price** card: the side card is **Usage** and counts the model calls.
- **With a price source**: the cost of each call follows its tokens (`· $0.0012`), the **Cost**
  card shows the conversation and the last turn, the **Model price** card the prices of the
  selected model, and the model picker puts the priced models first.
- **This stack configures no price source**: the chat shows tokens only. The code reads the prices
  from Langfuse's model table (`Catalog\PriceBook`) when it is given `TESTCHAT_LANGFUSE_URL` and
  the project's keys.

An admin sends tens of thousands of input tokens **per call**: the schemas of their tools.
`chat.tools.allow` (`config/packages/mcp.yaml`, tool-name prefixes) narrows the tools given to
the model for a focused benchmark.

## Traces (optional)

With an OpenTelemetry endpoint (`OTEL_EXPORTER_OTLP_ENDPOINT`), the chat sends one trace per turn:
session = conversation, user = the logged-in user. This stack sets none: tracing is a no-op.

## Quality

```bash
castor chat:quality    # in the chat container
```

It runs PHPStan (level max), PHPUnit, the JavaScript tests (`node --test 'tests/js/*.test.js'`) and
a syntax check of `public/app.js` (`node --check`), the same as `bin/ci/chat.sh`, the chat's CI
script. It is part of `castor quality:all`.
