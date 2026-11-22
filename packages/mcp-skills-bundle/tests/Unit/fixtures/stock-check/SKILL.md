---
name: stock-check
description: Check which products are about to run out. Use when the user asks about low stock.
metadata:
  version: "1.2.0"
  requires: "sylius:product_variant:read sylius:product:read"
---
# Stock check

1. List the variants.
2. Report the ones under five units, see [the thresholds](thresholds.md).
