---
title: A chat that drives the shop
user: admin
prompts:
  - 'List the 5 latest products'
  - 'Create a customer group "VIP"'
symfony_ai:
  - 'Agent: Symfony\AI\Agent\Agent with a toolbox, in the chat'
  - 'Platform: four providers behind PlatformInterface'
  - 'MCP client of symfony/mcp-bundle, its tools handed to the agent by McpToolbox'
  - 'MCP elicitation: the server asks the human before a write'
code:
  - apps/chat/src/Turn
  - packages/mcp-sylius-bundle/src/Tool
---
In the top bar of the chat, pick Anthropic and a Haiku model.

- **Look at** the badges above each answer: every model call with its tokens, every MCP Tool call with its duration.
- **The second sentence is a write**: a dialog asks for your confirmation before anything changes in the shop. Decline once to see the model report it, then ask again and accept.
- **Check** the new group in the back office: Customers, Groups.
