---
title: Ask the internal procedures
user: admin
prompts:
  - 'What do we do when a returned product arrives damaged?'
  - 'Is there a procedure for the customs duties of Switzerland?'
symfony_ai:
  - 'Store: a custom LoaderInterface makes a TextDocument of each section'
  - 'The SQLite store bridge indexes them; TextQuery searches by words'
  - 'HybridQuery and a Vectorizer as soon as an embeddings platform is configured'
code:
  - packages/mcp-knowledge-bundle
  - knowledge
---
The procedures of the business are Markdown files in `knowledge/`.

- **Look at** the first answer: it names the procedure, its section and its date. The second one says that no procedure covers the case, and invents nothing.
- **Edit** a file of `knowledge/`, ask again: the next search indexes what changed, without a restart.
