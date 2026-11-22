---
title: Screens in the conversation
user: admin
prompts:
  - 'Open the screen to edit the prices and stocks of the product Celestial_Harmony_T_Shirt'
  - 'Open the default address of customer 1'
symfony_ai:
  - 'MCP Apps of symfony/mcp-bundle: #[AsMcpApp] and #[AsMcpAppTool]'
  - 'A ui:// resource rendered with Twig, and tools only the app may call'
  - 'One generic app and three MCP Tools for any number of screens'
code:
  - packages/mcp-screens-bundle
  - packages/mcp-sylius-screens-bundle
  - apps/chat/public/app.js
---
Some things are easier to see and edit than to dictate.

- **On the first screen**: click Edit prices and stocks, type a wrong amount to see the check, then a real price and a new stock, save, confirm. Switch the channel with the select.
- **Look at** the tool calls: the model only knows `open_screen`. What the screen does afterwards goes through tools the model never sees, and a save asks for the same confirmation as any write.
- **The second screen** shows a person's address in clear: it needs one more grant than the first.
