---
name: morning-brief
description: The shop's morning brief - what to handle first today (orders to ship, yesterday's sales, failed payments, reviews to moderate, stock-outs, new customers), as five lines in the chat or as a report to present. Use it when the merchant asks for their morning brief, a daily summary, "what's up in the shop", or what to deal with first.
metadata:
  version: "1.0.0"
  requires: "sylius:order:read sylius:payment:read sylius:product_review:read sylius:product_variant:read sylius:customer:read"
---
# Morning brief

Tell the merchant what needs them this morning, most urgent first. You read; you change nothing.

## 1. Gather

Run these reads with the shop's MCP tools, in this order. "Yesterday" is the previous calendar day in the merchant's time zone; give dates as `YYYY-MM-DD`.

| What | Tool | Arguments |
|---|---|---|
| Orders to ship | `search_orders` | `paymentState: ["paid"]`, `shippingState: ["ready"]`, `sort: "completedAt"`, `direction: "asc"` |
| Yesterday's orders | `search_orders` | `completedFrom` and `completedTo`: yesterday |
| Failed payments | `search_payments` | `state: "failed"` |
| Reviews to moderate | `search_product_reviews` | `status: "new"` |
| Stock | `search_product_variants` | every page, keep the tracked variants with `onHand` at 0 |
| New customers | `search_customers` | `createdFrom`: yesterday |

- Use `total` for the counts: do not page through a list just to count it.
- Yesterday's revenue is the sum of `total` of yesterday's orders that are not cancelled, per currency. Amounts are in minor units: 5000 is 50.00.
- `search_payments` has no date filter: report the failed payments as "currently failed", not "since yesterday".
- A read that fails or is refused is reported as "not available", never guessed.

## 2. Rank

1. Orders paid and not shipped, oldest first. More than two days old is late: say so.
2. Failed payments.
3. Variants out of stock, the ones that sold yesterday first.
4. Reviews waiting for moderation.
5. Yesterday's sales and new customers: information, nothing to do.

A theme with nothing to report gets one short line ("No failed payment."), not a paragraph.

## 3. Present

Ask nothing: pick the view from the request. "Brief", "summary", "quick" is the short view. "Report", "to present", "slides", "for the team" is the report. When in doubt, the short view, then offer the report.

### Short view

Five lines at most, in the chat, in the merchant's language:

- one line per theme, the figure first: "7 orders to ship, the oldest from Monday (#000000012)";
- name the one item to open first (order number, product name);
- end each line that calls for it with the action you can take: "I can list them", "I can open the stock screen for this product".

### Report

A document to present, one section per theme, in this order: sales, to ship, incidents, stock, reviews.

- If you can produce slides, produce slides: one slide per theme, a title that states the fact ("3 orders are late"), three bullets at most, the figure large.
- Otherwise a document, or Markdown with one `##` per theme.
- First slide: the date and the three figures that matter. Last slide: the actions proposed, each one doable from this chat.

## 4. Offer the next step

Offer, never do it unasked: list the orders to ship, open the `product_variants` screen (`open_screen`) for a product out of stock, show the reviews to moderate.

## Updates

This skill is served by the shop's MCP server. When `list_skills` reports a version of `morning-brief` above 1.0.0, tell the merchant a newer version exists and that `get_skill` returns it.
