---
title: The same chat, another person
user: shop-reader
prompts:
  - 'Open the screen to edit the prices of the product Apollo_T_Shirt'
  - 'Create a customer group "Test"'
symfony_ai:
  - 'symfony/mcp-bundle server: its registry is decorated, tools/list and tools/call only serve what the caller''s grants allow'
code:
  - packages/mcp-core-bundle/src/Authorization
  - .castor/keycloak-realm.php
---
Click **Switch user** and log in as `shop-reader` (password: `shop-reader`), who reads everything and changes nothing.

- **Look at** the screen: it opens read-only. The write tool does not exist for this user: the model says so.
- **The Skills panel** offers `morning-brief` only: the other skill needs grants this user lacks.
- **Try `noaccess`**: a valid identity with no grant: the login works, and the server serves no tool.

Log back in as `admin` for the rest of the tour.
