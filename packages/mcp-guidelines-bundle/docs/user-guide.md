# MCP Guidelines Bundle: user guide

## What a guideline is

A guideline is a short text the business writes about itself.

It says who the business is, or how it writes one kind of content: product pages, blog posts, messages to customers.

Before the model writes a text for the business, it asks for the guidelines of what it is about to write.
It then follows them.

Guidelines are not skills (how to do a task) and not procedures (what to do in a case).

Everything is declared in the configuration.
The bundles that bring tools know nothing of it.

This guide is for the merchant who writes the guidelines and for whoever configures the server.

> **Security.**
> The guidelines go into the model's instructions as rules to follow.
> Whoever writes them steers what the model writes for the business.
> Keep them as Markdown files in your repository, reviewed like code.
> A resource read from a source people can change outside that review (a CMS, a wiki) lets them write instructions to the model: prompt injection.

## What happens in a conversation

1. At the start, the server's instructions list the **intents**, what the model may be about to write, each with a description.
   They say: before writing any text for the business, you MUST call `get_guidelines(intents: [...])`.
2. The user asks "Write the page of the new Aurora sweatshirt".
3. The model matches its task to the description "Product written content" and calls `get_guidelines(intents: ["product_description"])`.
4. The server answers with the guidelines of that intent, `company` then `catalog_content`.
5. The model writes the text following them, then saves it.

The model chooses the intent: clear descriptions make it choose well.

Changing a status, a price or a stock is not writing: no guideline is asked for.

## The MCP tool `get_guidelines`

- **Argument**: `intents`, a list, for example `["product_description", "blog_post"]`.
- **Returns**: `{guidelines: [{name, content}]}`, the guidelines of those intents, each once, in order.
- **Unknown intent**: the answer adds `unknown_intents` and the valid `intents`.

## The guidelines shipped by default

The bundle ships six generic guidelines of an online retailer, in `resources/guidelines/`.
Replace them with your own.

| Name | What it covers |
|---|---|
| `company` | Identity, voice, what we never claim |
| `catalog_content` | Product and category texts, meta fields |
| `website_information` | About, delivery, returns, FAQ |
| `website_post` | Blog posts and buying guides |
| `promotional_content` | Promotions, banners, coupons |
| `customer_message` | Order emails, replies to customers and reviews |

## Setting it up

### Installed alone

Without configuration the bundle declares its six guidelines and no intent.

Nothing is added to the server's instructions: the model is not asked to call `get_guidelines`.

### Declaring guidelines and intents

In `config/packages/emagma_mcp_guidelines.yaml` of the application:

```yaml
emagma_mcp_guidelines:
    resources:
        - '@EmagmaMcpGuidelinesBundle/resources/guidelines/company.md'
        - '%kernel.project_dir%/config/guidelines/catalog_content.md'
    intents:
        product_description:
            description: 'Product written content'
            guidelines: [company, catalog_content]
```

- **`resources`**: one guideline per line.
  Its name is the file name without `.md` (`catalog_content.md` is `catalog_content`).
  `@Bundle/...` points to a file of a bundle.
- **Your own text**: point the line to your file, the name does not change.
- **`intents`**: what the model may write.
  `description` is what it matches its task against.
  `guidelines` are given in that order, and several can serve one text.
- **Another loader**, brought by another bundle: `- { resource: 'https://wiki.example.com/legal', loader: wiki, name: legal_notice }`.

The server does not start when:

- no loader can read a resource,
- a Markdown file does not exist,
- two resources have the same name,
- an intent names an unknown guideline.

### When a change applies

An edited file applies to the next call.
A new resource or intent needs `cache:clear`.

## Who may use it

| MCP tool | Grant |
|---|---|
| `get_guidelines` | `mcp:core:guideline:read` |

## Command

`mcp:guidelines` prints each guideline (name, loader, resource, size) and each intent (description, guidelines).

```bash
bin/console mcp:guidelines
```

## Limits and things to know

- **The model may not call `get_guidelines`.**
  The instructions say MUST, nothing enforces it.
- **A conversation keeps what it read.**
  Start a new one after a change.
- **Keep the guidelines short.**
  They are paid for each time they are given.
- **The facts are not in the guidelines.**
  Prices, figures and dates come from the tools.
