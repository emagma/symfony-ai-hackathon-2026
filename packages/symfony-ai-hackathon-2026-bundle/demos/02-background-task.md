---
title: A long task in the background, followed from the chat
user: admin
prompts:
  - 'Translate the products Galaxy_Ride_T_Shirt and Quasar_Bloom_T_Shirt into Italian'
  - 'Launch it'
  - 'Where is the translation task?'
symfony_ai:
  - 'Platform: PlatformInterface::invoke() with a MessageBag, outside any chat turn: the reading of the scripts, then llm_transform from the worker'
  - 'PlatformOptions::MAX_OUTPUT_TOKENS always set'
  - 'The server''s own provider (ai.platform.anthropic), whatever model the chat uses'
code:
  - packages/mcp-tasks-bundle
  - packages/mcp-tasks-messenger-bundle
  - packages/symfony-ai-hackathon-2026-bundle/src/ProductTranslation
  - skills/prepare-task/SKILL.md
---
Translating a catalogue takes longer than a chat turn should. The MCP Tool prepares a background task: PHP scripts that call the same MCP Tools as the chat. You read what they will do, you launch, a worker plays them with your rights.

- **Why these two products**: the fixtures' texts are filler Latin, and their "translation" stays Latin. Galaxy Ride and Quasar Bloom have a real English name, short description and description; Aurora Breeze, Nebula Glow and Orbit Pulse (`Aurora_Breeze_T_Shirt`, `Nebula_Glow_T_Shirt`, `Orbit_Pulse_T_Shirt`) too, without a short description. No product has an Italian translation yet.
- **Look at** the first answer: a draft task and its reading, what the scripts do in plain language. Nothing ran.
- **`Launch it`** asks for your confirmation. The first time, a link asks you to let the server act for you while you are away: open it, then launch again.
- **Ask where it is**: the step being played and the number of tool calls. You get an email when it is over (Mailpit).
- **Check** the two products in the back office, Italian tab, and on the storefront: the main channel offers Italian, `http://localhost/it_IT/products/<the Italian slug>` shows the translation.
- **Go further**: ask for a long treatment no tool was written for (`Give every product of the T-shirts category a shorter description`): the model writes the scripts itself.
- **If the task stays queued**: its worker is down, `castor docker:logs mcp-tasks-worker`.
