---
title: An agent that works while you are away
user: admin
prompts:
  - 'What did the fraud agent report?'
symfony_ai:
  - 'Agent with SystemPromptInputProcessor (its risk rules), run by a message the shop''s event puts in a queue: no chat, no user'
  - 'The model is asked only for the ambiguous cases, and sees the shape of the address, never the address'
code:
  - packages/sylius-mcp-event-plugin
  - packages/mcp-sylius-events-bundle
  - packages/mcp-agents-bundle
  - packages/symfony-ai-hackathon-2026-bundle/src/FraudCheckerAgent
---
Before typing anything, give the agent something to find:

1. Create an account on the shop, http://localhost/en_US/register, with an address such as `x9k2qz7p4m@mailinator.com`.
2. Wait a few seconds: the new account wakes the agent. To run it by hand anyway: `castor mcp:console hackathon:agent:fraud`.
3. Open Mailpit, http://localhost:8025: the alert is there. Nothing? `castor docker:logs mcp-agents-worker`.

Then type the sentence in the chat.

- **Look at** the report: the level, and the rules that fired. The agent only informs: nothing was changed in the shop.
- **Also at work**: Symfony Webhook (the shop tells the server), Messenger (one deduplicated `RunAgent` in `mcp_agents`, played by `mcp-agents-worker`), Lock (never two passes at once), Scheduler (every five minutes, the safety net), Notifier (the alert, sent through the `async` queue).
