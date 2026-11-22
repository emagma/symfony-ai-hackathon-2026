---
title: Guidelines the server hands to the model
user: admin
prompts:
  - 'Which guidelines do you follow when you write a product description?'
  - 'Rewrite and save the short description of the product Apollo_T_Shirt'
symfony_ai:
  - 'MCP server instructions assembled from every bundle: the guidelines bundle lists the intents'
  - 'Bundle configuration: the guidelines and the intents are declared, not coded'
code:
  - packages/mcp-guidelines-bundle
  - apps/mcp-server/config/packages/emagma_mcp_guidelines.yaml
---
Who the business is and how it writes each kind of content belong to the business, not to each user's prompt: the server tells the model what it may write, and the model asks for the guidelines of what it is about to write.

- **Look at** the `get_guidelines(intents: ["product_description"])` call: it returns `company` and `catalog_content`, and the rewritten description follows them.
- **In a terminal**: `castor mcp:console mcp:guidelines` lists the guidelines and the intents.
- **Make it yours**: in `emagma_mcp_guidelines.yaml`, point a line to your own Markdown file, or add an intent.
