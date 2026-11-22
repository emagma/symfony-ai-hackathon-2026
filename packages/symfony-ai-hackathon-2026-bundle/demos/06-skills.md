---
title: Skills served by the server
user: admin
prompts:
  - 'Give me my morning brief'
symfony_ai:
  - 'MCP Tools returning content: get_skill answers with EmbeddedResource items, not JSON'
  - 'Agent: the installed skills are named in the system prompt of the chat''s agent at every turn'
code:
  - packages/mcp-skills-bundle
  - skills
  - apps/chat/src/Skill
---
First, in the Skills panel on the right of the chat, install `morning-brief`. Then type the sentence.

- **Look at** the calls: `get_skill` first, then the reads the skill lists, in its order.
- **The skill is a Markdown file**, `skills/morning-brief/SKILL.md`: change a rule in it and ask again.
- **Ask for the other view**: "as a report to present".
