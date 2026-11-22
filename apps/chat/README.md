# Chat - the test application of the MCP server

> **A test application, nothing else.**
> The chat is made to try the MCP server on your own machine, with several models and several users.
> It is not a tool to develop with, and it must never be deployed:
>
> - its `APP_SECRET` is a public placeholder (`apps/chat/.env`);
> - its Keycloak client, `mcp-testchat`, is declared for local tests only;
> - in the `dev` environment, the MCP server turns off the protection of personal data for this client: the model sees names, emails and addresses in clear;
> - it calls the models with the provider keys of whoever runs it.

`apps/chat` drives the shop **through the MCP server** (`/_mcp` of `apps/mcp-server`), the way Claude or ChatGPT would, with a model called directly on its provider's API: OpenAI, Anthropic, Mistral or Qwen.
Since it calls the model itself, it shows what a hosted client hides: the exact model and the tokens of every model call.
It is the tool to compare models, user profiles and versions of the server.

## Start

1. Put a provider key in `.project.local.ini`:

   ```ini
   [infrastructure.ai.anthropic]
   api_key = "sk-ant-..."
   ```

2. Apply it and start the chat:

   ```bash
   castor init:env
   castor docker:up      # or: castor chat:up, the chat alone
   ```

3. Open http://localhost:8090 and log in with a demo user (password = username).

## Documentation

- [User guide](docs/user-guide.md): start, models, conversations, write confirmations, screens, skills, tokens.
- [Developer guide](docs/developer-guide.md): topology, a turn and its events, confirmations and timeouts, screens, data, tests.
