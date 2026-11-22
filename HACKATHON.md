# Symfony AI & MCP Hackathon 2026 - getting hands on

A Sylius shop driven from a chat, through an MCP server built with Symfony AI.
This page takes you from a fresh clone to every demo, and says for each one
which Symfony AI building block it exercises.

- **What runs**: a Sylius 2.3 shop, the MCP server (Symfony 7.4, `symfony/mcp-bundle`), a test chat
  (Symfony AI agent, MCP client), Keycloak, Mailpit. One `docker compose` stack.
- **Time needed**: about five minutes to start, then five minutes per demo.
- **Cost**: the demos call Claude Haiku with your key; the whole tour stays well under one dollar.

## 1. Before you start

You need Docker Engine with Compose v2 or later, [Castor](https://castor.jolicode.com/getting-started/installation/)
(`curl "https://castor.jolicode.com/install" | bash`), `bash`, `openssl`, `sudo` once for
`/etc/hosts`, free ports and an [Anthropic API key](https://console.anthropic.com/settings/keys).
The [README](README.md#requirements) lists them, with the ports.

One key serves the chat and the MCP server's own background work (tasks, fraud agent). Other
providers (OpenAI, Mistral, Qwen) work in the chat only; the server's background work uses
Anthropic.

## 2. Start the stack

1. Clone the repository:

   ```bash
   git clone https://github.com/emagma/symfony-ai-hackathon-2026.git
   cd symfony-ai-hackathon-2026
   ```

2. Put your Anthropic key in `.project.local.ini` (git-ignored, never committed), replacing
   `sk-ant-...` with your key:

   ```bash
   printf '[infrastructure.ai.anthropic]\napi_key = sk-ant-...\n' > .project.local.ini
   ```

3. Start the stack:

   ```bash
   castor init
   ```

   It builds the image, installs the three applications, imports the Keycloak realm, seeds the shop
   and verifies the whole, the key included. Say yes when it offers to add `127.0.0.1 keycloak` to
   `/etc/hosts`: the login needs it.

Key forgotten? Add the section above to `.project.local.ini`, then
`castor init:env && castor docker:up`; `castor init:verify` tells whether the key reaches the
containers.

## 3. Open the applications

| What | URL | Log in |
|---|---|---|
| Chat | http://localhost:8090 | Keycloak, `admin` / `admin` |
| Shop | http://localhost/en_US/ | - |
| Shop back office | http://localhost/admin | see `castor init:info` |
| Mailpit (emails) | http://localhost:8025 | - |

Also there: Keycloak on http://localhost:8085, the MCP endpoint on http://localhost:8081/_mcp. `castor init:info` prints
every URL and credential; `castor init:verify` checks that everything answers.

In the chat, pick **Anthropic** and `claude-haiku-4-5-20251001` in the top bar (the chat
preselects a Sonnet model, which costs more). The chat is already running after `castor init`:
it is the way the tour is meant to be played.

Demo users (password = username):

| User | What they may do |
|---|---|
| `admin` | everything |
| `shop-reader` | read everything, change nothing |
| `catalog-manager` | read and write the catalogue only |
| `noaccess` | nothing (valid identity, zero grant) |

### Your own Claude instead of the chat

The project's MCP server is a standard MCP server: `http://localhost:8081/_mcp`, Streamable HTTP,
OAuth with the demo users. Its parameters are in the README,
[Use it from Claude, ChatGPT or Claude Code](README.md#use-it-from-claude-chatgpt-or-claude-code).

- **Claude Code** (local, no tunnel): start `claude` in the clone, accept the `sylius-mcp` server
  of `.mcp.json`, then `/mcp` → `sylius-mcp` → Authenticate, and log in as `admin`. Elsewhere:
  `claude mcp add --transport http --client-id mcp-cli sylius-mcp http://localhost:8081/_mcp`.
- **Claude web or desktop**: add a custom connector with the URL `castor tunnel:up` prints and
  the OAuth client `mcp-llm`. The tunnel makes the demo users public while it is open: close it
  with `castor tunnel:down`.
- **Either way**, the conversation's model runs on your Claude plan; the server's own model calls
  (demo 2's task, demo 3's fraud agent) still use your Anthropic key.
- **The tour**: ask `Guide me through the demos of the hackathon`. What differs from the chat
  (token badges, Writes switch, Skills panel, screens, switching user) is listed in the README.

## 4. The demos

Run them in this order as `admin`. Each one names what to type, what to look at, and the Symfony AI
concepts behind it.

The chat can guide you through the same tour: type `Guide me through the demos of the hackathon`
(there is a button for it on the start screen), or run `castor mcp:console hackathon:demos`.

### Demo 0 - A chat that drives the shop

- **Type**: `List the 5 latest products`, then `Create a customer group "VIP"`.
- **Look at**: the badges of each model call (its tokens) and each MCP Tool call; the confirmation
  dialog before the write. It accepts the write by itself after 30 seconds: answer it before then,
  and keep the **Writes** switch on **Confirm**.
- **Symfony AI**
  - Agent: `Symfony\AI\Agent\Agent` with a toolbox, in `apps/chat`.
  - Platform: one agent, four providers behind `PlatformInterface`.
  - MCP client: `symfony/mcp-bundle` client, and its tools handed to the agent by `McpToolbox`.
  - MCP elicitation: the server asks the human before a write; the chat answers with its dialog.
- **Code**: `apps/chat/src/Turn/`, `packages/mcp-sylius-bundle/src/Tool/`.

### Demo 1 - Guidelines the server hands to the model (E)

- **Type**: `Which guidelines do you follow when you write a product description?`, then
  `Rewrite and save the short description of the product Apollo_T_Shirt`.
- **Look at**: the server's instructions list what the model may write (intents); before writing,
  the model calls `get_guidelines(intents: ["product_description"])` and gets `company` and
  `catalog_content`; the rewritten description follows them. `castor mcp:console mcp:guidelines`
  lists the guidelines and the intents.
- **Make it yours**: point a line of `apps/mcp-server/config/packages/emagma_mcp_guidelines.yaml` to your
  own Markdown file, or add an intent; no code changes.
- **Symfony AI and MCP**
  - MCP server instructions: every bundle adds its block (a tagged service with a priority), the
    core assembles them.
  - Bundle configuration: the guidelines and the intents are declared, not coded.
- **Code**: `packages/mcp-guidelines-bundle`.

### Demo 2 - A long task in the background, followed from the chat (C)

- **Type**: `Translate the products Galaxy_Ride_T_Shirt and Quasar_Bloom_T_Shirt into Italian`,
  then `Launch it`, then `Where is the translation task?`. These two have real English texts; the
  rest of the fixtures is filler Latin, which stays Latin once "translated".
- **Look at**: `product_translate` returns at once with a draft task and its reading: what its
  scripts will do, in plain language, in English. Nothing runs before `start_task` and your
  confirmation. The first launch gives a link: open it once to let the server act for you while
  you are away. Then `get_task` shows the step being played and the tool calls made; an email
  arrives in Mailpit at the end. Check the two products in Italian afterwards, in the back office
  and on the storefront (`http://localhost/it_IT/products/<the Italian slug>`).
- **Go further**: ask for a long treatment no tool was written for. The model writes the scripts
  itself (`create_task`, skill `prepare-task`).
- **Symfony AI**
  - Platform: `PlatformInterface::invoke()` with a `MessageBag`, outside any chat turn: the
    reading of the scripts, then `llm_transform` called by the worker.
    `PlatformOptions::MAX_OUTPUT_TOKENS` is always set.
  - Server-side model: the server's own provider (`ai.platform.anthropic`), whatever model the
    chat uses.
- **Also**: Symfony Messenger (queue `mcp_tasks`, service `mcp-tasks-worker`), Process (the scripts run
  in a hardened PHP process), Notifier.
- **Code**: `packages/mcp-tasks-bundle`, `packages/mcp-tasks-messenger-bundle` (the steps as
  messages), `packages/symfony-ai-hackathon-2026-bundle/src/ProductTranslation`; the design in the
  [tasks bundle's developer guide](packages/mcp-tasks-bundle/docs/developer-guide.md).

### Demo 3 - An agent that works while you are away (D)

- **Do**: create an account on the shop (http://localhost/en_US/register) with an address such as
  `x9k2qz7p4m@mailinator.com`. The new account wakes the agent within seconds. To run it by hand
  anyway (it says so when a pass is already running):

  ```bash
  castor mcp:console hackathon:agent:fraud
  ```

- **Look at**: the alert email in Mailpit; in the chat, `What did the fraud agent report?`.
- **Symfony AI**
  - Agent: `Agent` with `SystemPromptInputProcessor` (its risk rules), run by a message the
    shop's event puts in a queue - no chat, no user.
  - The model is asked only for the ambiguous cases, and sees the shape of the address, never the
    address: the rules in code decide the clear ones.
- **Also**: Symfony Webhook and RemoteEvent (the shop tells the server), EventDispatcher (the event
  recorded wakes the agent), Messenger (`RunAgent` in `mcp_agents`, deduplicated, played by
  `mcp-agents-worker`), Lock (never two passes at once), Scheduler (every five minutes, the safety
  net, `mcp-scheduler`), Notifier (the alert, sent through the `async` queue).
- **Code**: `packages/sylius-mcp-event-plugin`, `packages/mcp-sylius-events-bundle`,
  `packages/mcp-agents-bundle`, `packages/symfony-ai-hackathon-2026-bundle/src/FraudCheckerAgent`.

### Demo 4 - Ask the internal procedures (F)

- **Type**: `What do we do when a returned product arrives damaged?`, then
  `Is there a procedure for the customs duties of Switzerland?`.
- **Look at**: the first answer quotes the procedure, its section and its date; the second says
  that no procedure covers the case. Edit a file of `knowledge/` and ask again: the next search
  indexes what changed.
- **Symfony AI**
  - Store: a custom `LoaderInterface` turns each `##` section into a `TextDocument`; the SQLite
    store bridge indexes them; `TextQuery` searches by words (FTS5).
  - Hybrid search, by words and meaning, with `HybridQuery` and a `Vectorizer` as soon as
    `emagma_mcp_knowledge.embeddings` names an embeddings platform.
- **Code**: `packages/mcp-knowledge-bundle`, the procedures in `knowledge/`.

### Demo 5 - Screens in the conversation (A)

- **Type**: `Open the screen to edit the prices and stocks of the product Celestial_Harmony_T_Shirt`.
  Click **Edit prices and stocks**, change a price, save, confirm. Switch the channel.
  Then `Open the default address of customer 1`.
- **Look at**: the model only knows `open_screen`; the form validates what you type; the save
  asks for the same confirmation as any write.
- **Symfony AI**
  - MCP Apps of `symfony/mcp-bundle`: `#[AsMcpApp]` and `#[AsMcpAppTool]`, a `ui://` resource
    rendered with Twig, tools visible to the app only.
  - One generic app and three MCP Tools for any number of screens.
- **Also**: the chat is the MCP Apps host - a sandboxed iframe and a `postMessage` bridge.
- **Code**: `packages/mcp-screens-bundle`, `packages/mcp-sylius-screens-bundle`,
  `apps/chat/public/app.js`.

### Demo 6 - Skills served by the server (B)

- **Do**: in the **Skills** panel of the chat, install `morning-brief`.
- **Type**: `Give me my morning brief`.
- **Look at**: the model reads the skill (`get_skill`), runs its reads in order and answers with
  the short view. The skill is a Markdown file of `skills/`: edit it and ask again.
- **Symfony AI**
  - MCP Tools returning content: `get_skill` answers with `EmbeddedResource` items
    (`skill://merchant/<name>/SKILL.md`), not with JSON.
  - Agent: the installed skills are named in the system prompt of the chat's agent at every turn.
- **Code**: `packages/mcp-skills-bundle`, the skills in `skills/`.

### Demo 7 - The same chat, another person

- **Do**: **Switch user**, log in as `shop-reader`.
- **Type**: `Open the screen to edit the prices of the product Apollo_T_Shirt`, then
  `Create a customer group "Test"`.
- **Look at**: the screen opens read-only; the write tool does not exist for this user; the Skills
  panel offers `morning-brief` only. Try `noaccess`: the login works, but the server serves no tool.
- **Symfony AI**
  - `symfony/mcp-bundle` server: the registry is decorated, so `tools/list` and `tools/call` only
    serve what the caller's grants allow.
- **Code**: `packages/mcp-core-bundle/src/Authorization/`, the realm in
  `.castor/keycloak-realm.php`.

## 5. Where Symfony AI is used

| Component | Demos | Where |
|---|---|---|
| Platform | 0, 2, 3 | chat, translation task, fraud agent |
| Agent | 0, 3, 6 | chat, fraud agent |
| Store | 4 | procedures |
| MCP bundle, server | all | `apps/mcp-server` |
| MCP bundle, client | 0, 5, 6 | `apps/chat` |
| MCP Apps | 5 | screens |

## 6. If something does not work

- **The chat login loops or fails**: `keycloak` must resolve to `127.0.0.1` (`/etc/hosts`).
- **A provider is greyed out in the chat**: its key is missing; see section 2.
- **A task stays "queued"**: its worker is down - `castor docker:logs mcp-tasks-worker`.
- **No fraud report or alert arrives**: the agents' worker is down - `castor docker:logs mcp-agents-worker`
  (it also sends the emails).
- **The shop data looks wrong**: `castor shop:db` reloads the same catalogue, customers and orders.
- **Anything else**: `castor init:verify`, then `castor docker:logs <service>`.

Going further: [README.md](README.md) (how the applications fit together),
[apps/chat/README.md](apps/chat/README.md) (the chat), the hackathon bundle's
[user guide](packages/symfony-ai-hackathon-2026-bundle/docs/user-guide.md) (what to check after
each demo), and the two guides in each `packages/*/docs/`.
