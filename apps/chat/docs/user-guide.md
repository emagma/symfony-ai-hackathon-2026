# Chat: user guide

> **A test application.**
> The chat is made to try the MCP server on your own machine, with several models and several users.
> It is not a tool to develop with, and it must never be deployed:
>
> - its `APP_SECRET` is a public placeholder (`apps/chat/.env`);
> - its Keycloak client, `mcp-testchat`, is declared for local tests only;
> - in the `dev` environment, the MCP server turns off the protection of personal data for this client: the model sees names, emails and addresses in clear;
> - it calls the models with the provider keys of whoever runs it.

## What it does

The chat drives the shop **through the MCP server** (`/_mcp` of `apps/mcp-server`), the way Claude or ChatGPT would.
It calls the model itself, on its provider's API: OpenAI, Anthropic, Mistral or Qwen.

So it shows what a hosted client hides: the exact model, and the tokens of every model call.
It is the tool to compare models, user profiles and versions of the server.

What it measures: *this agent, with this model, through the MCP server*.
Not the cost of a conversation in a hosted client, which adds its own system prompt and its own rules for using the tools.

This guide is for whoever tests the MCP server with the chat.
How the chat is built: the [developer guide](developer-guide.md).

## Start

1. Put the provider keys in `.project.local.ini`, one section per provider:

   ```ini
   [infrastructure.ai.anthropic]
   api_key = "sk-ant-..."
   ```

   Sections: `openai`, `anthropic`, `mistral`, `qwen`.
   A provider without a key is greyed out in the chat.
2. Apply them and start the chat:

   ```bash
   castor init:env       # writes the keys to docker/.env
   castor docker:up      # or: castor chat:up, the chat alone
   castor chat:info      # its URL and the providers whose key is set
   ```

3. Open http://localhost:8090.
   The page sends you to Keycloak: log in with a demo user (password = username, see the [Keycloak developer guide](../../../packages/mcp-auth-keycloak-bundle/docs/developer-guide.md#demo-users-stack-specific)).

- **Where the keys never go**: `apps/chat/.env`.
  That file is committed, and the keys would be ignored there anyway: compose sets the variables (`TESTCHAT_<PROVIDER>_API_KEY`), and a real environment variable wins over a `.env` file.
  `castor chat:info` warns when it finds one there.
- **Already running**: `compose.chat.yaml` is part of the default stack, so `castor init` installs and starts the chat.

## Models

- **Listed live**: the models each provider's API lists at the moment (cached one hour), kept to those that can hold a conversation with tools.
- **Default**: `default_model` of each provider in `config/packages/chat.yaml` is preselected until the browser remembers another choice.
- **Changing model mid-conversation**: allowed.
  Each call is counted with its own model.
- **Qwen**: on DashScope's international region by default (`TESTCHAT_QWEN_BASE_URL`).

## The page

- **Top bar**: the Provider and Model pickers, the **Writes** switch, the tokens of the conversation, the logged-in user and **Switch user**.
- **Theme**: the button at the top right toggles the dark theme.
- **Answers**: rendered as a small subset of Markdown.
- **Activity**: each model call, tool call and confirmation shows as a badge in the conversation, while the turn runs.

## Conversations

- **The list**: the left column lists your conversations, latest first, grouped by day (Today, Yesterday, Last 7 days, Older).
  Each shows its title (the first question) and the last model used.
- **Reopening**: a click reopens a conversation with its messages and its activity.
  The URL carries its id (`#<uuid>`), so each browser tab can keep one open.
- **Deleting**: final.
  Not possible while a turn is running.
- **Privacy between users**: nobody sees or deletes another user's conversation.
- **Older versions**: a conversation saved by an earlier version of the chat may no longer open.
  The page keeps working.

## Write confirmations

The server asks for a human confirmation before every write.
The chat shows it in a dialog, **Confirm the write?**, with a countdown and three buttons: **Accept**, **Decline**, **Cancel**.

- **Without an answer after 30 s**, the write is **accepted automatically**.
- **The Writes switch**, in the top bar, is remembered by the browser.
  Set to **Auto-accepted**, every confirmation is accepted at once, without the dialog; the badge says so.
  Handy to chain write tests.
  Switch it back to **Confirm** before testing the confirmation flow itself.

## Screens

When the model opens a screen (`open_screen`: the prices and stocks of a product's variants, an address), it shows in the conversation.

- **Its buttons** call the server directly.
  A save goes through the same confirmation dialog as a turn.
- **Reopening** a conversation shows its screens again, and they still work.

## Skills

The **Skills** panel lists the skills the MCP server offers you (filtered by your grants) and those you installed.
It is hidden when there are none.

- **Install**: the model is told about the skill at each turn, and reads its instructions on the server when your request matches.
- **Update**: a new version on the server is flagged in the panel (`v1 installed · v2 available`).
  The instructions read are always the server's.
- **Remove**: the model no longer applies the skill, and offers to install it instead.
  A skill the server no longer offers can only be removed.

## Tokens

Always shown, in millions of tokens with four decimals (`MT`; 0.0001 MT is 100 tokens):

- **Under each model call**: `claude-haiku-4-5 · in 0.0123 MT · out 0.0003 MT · cache read 0.0098 MT · cache write 0.0000 MT`.
  `in` is the input **without** the cache: the four parts add up.
- **In the top bar**: the total of the conversation.

An admin sends tens of thousands of input tokens **per call**: the schemas of their tools.
`chat.tools.allow` (`config/packages/mcp.yaml`, tool-name prefixes) narrows the tools given to the model for a focused comparison.

No cost is shown: the chat shows a cost only when it is given a price source, and this delivery gives none.
The side card is **Usage** and counts the model calls.

## Without a model

The same client, from the command line:

```bash
castor chat:console testchat:catalog              # models listed, per provider
castor chat:console testchat:catalog mistral      # every model of one provider
castor chat:console testchat:mcp:call search_products '{"itemsPerPage":2}' --user=catalog-manager
castor chat:console testchat:mcp:call upsert_customer_groups '{"items":[{"code":"x","name":"X"}]}' --answer=decline
```

`testchat:mcp:call` logs in as a demo user and answers a write confirmation with `--answer` (`accept` or `decline`, `decline` by default).
