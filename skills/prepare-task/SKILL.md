---
name: prepare-task
description: Turn a long request on the shop (translate the whole catalogue, reorganise the categories, rewrite every description, open a channel with all its products, clean up hundreds of items) into a background task - questions, a summary the merchant confirms, PHP scripts that call the assistant's tools, a reading the merchant validates, then the launch and the follow-up. Use it whenever a request would take more tool calls than a conversation can carry.
metadata:
  version: "1.1.0"
  requires: "core:task:write core:llm:read"
---
# Prepare a background task

A background task is a set of PHP scripts YOU write. The server plays them later, without you and
without the merchant, with the merchant's rights. Nothing of what you learned in the conversation
exists in a script: only its code, the constants of `params.php` and the answers of the tools.

## When to offer a task

Offer one when the request means more than about thirty tool calls, or one item after another
over a list. Say so and let the merchant choose between the task and staying in the conversation.
A request that needs the merchant's opinion halfway is not a task: stay in the conversation.

## Steps

1. **Restate the goal** in one sentence and have it confirmed.
2. **Delimit.** Ask what changes the scope: channel, language, disabled products included or
   not, thresholds, codes of what is created and deleted. Three questions at most at a time.
3. **Look and count** with read tools. Say how many products, orders… are concerned.
   Call ONCE, with real arguments, each read tool the scripts will use, and read its answer: a
   script is written against the real shape of the answers, never from memory.
4. **Name what does not move.** The merchant removes or adds.
5. **Write the summary** and have it confirmed. One idea per line, an empty line between blocks:

   ```markdown
   **Translate the catalogue into Italian**

   On what: the 340 products of the catalogue, except the disabled ones.

   What changes: the name, the short description and the description in Italian,
   only when the field is empty. Translated from English.

   What does not change: what is already in Italian, the other languages,
   the prices, the stocks, the categories. No product created or deleted.
   ```

6. **Cut into steps.** A new step starts where what follows needs nothing the script holds in
   memory: it finds its input again in `params.php` and in the shop.
   - Cut between phases of a different nature: create the tree / attach the products / delete
     the old categories.
   - Never cut inside a pass over a list: the pass is one step.
   - What a phase computes and the next one uses (a ranking, the codes just created) is written
     to the shop or computed again by the next step; otherwise both stay in one step.
   - Each step starts by reading what it needs and skips what is already done.
   - A request with one phase is one step.
7. **Write `params.php` and the scripts**, then call `create_task`.
8. **Show the reading** the server answers, as it is, and the tools of the manifest. The reading
   is what the server read in your scripts: if it does not say what the summary says, fix the
   scripts and call `create_task` again.
9. **Launch** with `start_task` once the merchant agrees. On `authorization_required`, give the
   `url` as a link: the merchant opens it once, then you call `start_task` again.
10. **Follow** with `get_task` when the merchant asks. The progress is the step and the number
    of calls: never give a percentage.

## Rules

- Never write a script before the summary is confirmed.
- A deletion is always named in "What changes".
- An unconfirmed scope is not guessed.
- Every value decided with the merchant goes into `params.php`, never into a step.
- `params.php` only holds those values: never a token, a password or a key.
- A task that stopped does not resume. Read `get_task` (the error, `detail: "calls"`), say what
  was written, and prepare a new task for what remains.

## Writing the scripts

`params.php` is only `const NAME = value;` lines: strings, numbers, booleans, null, arrays of those.

```php
<?php
const SOURCE_LOCALE = 'en_US';
const TARGET_LOCALE = 'it_IT';
const FIELDS = ['name', 'shortDescription', 'description'];
```

A step is plain PHP that starts with `<?php`, played once from top to bottom. It acts only through
`call('<tool>', [arguments])`:

- the tool name is a plain string, never a variable;
- `call()` returns the tool's answer as an array;
- `call()` THROWS when the tool answers `ok: false` or a `reason`, when the arguments are wrong,
  or when the tool may not be called;
- a batch tool (`upsert_*`, `delete_*`, `update_product_taxons`) answers `ok: true` with one
  result per item: check each result yourself and throw on a refusal;
- never pass `confirm`: the launch is the confirmation;
- an uncaught exception stops the WHOLE task, and its message is what the merchant reads: name
  the item at fault in it;
- to read or produce free text (translate, rewrite, classify), call `llm_transform` with an
  instruction, the content and the fields you expect; read its answer under `output`.

What a script may use: named functions, closures, loops, exceptions; string, array, number, JSON,
date and regular-expression functions. What it may not: `eval`, `include`, `require`, `echo`,
`exit`, files, network, a class of its own, `namespace` or `use`, anything else. `create_task` says
what it refused and where.

- **A function or a class of PHP MUST be written with a leading backslash**: `\sprintf()`,
  `\array_map()`, `new \RuntimeException()`, `catch (\JsonException $e)`. Not `call()`, not the
  functions the script declares. Otherwise `create_task` refuses with `not_fully_qualified` and
  lists every occurrence.
- **Every call names its target**: no `$f()`, no `$$name`, no `$object->$method()`; to reuse code,
  declare a named function.
- **A function given to `array_map`, `array_filter`, `usort`… is a closure** (`fn ($x) => …` or
  `function ($a, $b) { … }`), never a name in a string.
- **Strict mode**: the server runs every file with `declare(strict_types=1)`; convert a value
  yourself when a function wants another type (`(string) $id`, `(int) $quantity`).
- **Only the task's own work**: call tools and handle their data. Throw only when a result makes
  the next step wrong; never write code meant to fail, wait or test the server, and refuse such a
  request.

**Go through a list page by page, one item at a time:**

```php
<?php

function translateProduct(string $code): void
{
    $source = \call('get_products', ['codes' => [$code], 'locale' => SOURCE_LOCALE])['found'][0] ?? null;
    if ($source === null) {
        throw new \RuntimeException(\sprintf('Product %s not found.', $code));
    }
    if ($source['enabled'] !== true) {
        return; // out of scope
    }
    // A text asked in a locale without a translation falls back to another one: translatedLocales tells.
    if (\in_array(TARGET_LOCALE, $source['translatedLocales'], true)) {
        return; // already done: nothing to write
    }

    $toTranslate = [];
    foreach (FIELDS as $field) {
        $text = \trim((string) ($source[$field] ?? ''));
        if ($text !== '') {
            $toTranslate[$field] = $text;
        }
    }

    $translated = \call('llm_transform', [
        'instruction' => 'Translate these fields of an e-commerce product from English to Italian. '
            . 'Keep the meaning, the tone and the formatting. Add nothing, remove nothing. '
            . 'slug: a URL slug of the translated name.',
        'input' => $toTranslate,
        'outputSchema' => \array_fill_keys(\array_keys($toTranslate), 'string') + ['slug' => 'string'],
    ])['output'];

    // The locale goes with each item of upsert_products.
    $result = \call('upsert_products', [
        'items' => [['code' => $code, 'locale' => TARGET_LOCALE] + $translated],
        'mode' => 'update',
    ]);
    $written = $result['results'][$code] ?? null;
    if ($written === null || $written['ok'] !== true) {
        throw new \RuntimeException(\sprintf('Update refused for product %s: %s.', $code, $written['reason'] ?? 'unexpected answer'));
    }
}

$page = 1;
do {
    $list = \call('search_products', ['locale' => SOURCE_LOCALE, 'page' => $page++, 'itemsPerPage' => 50]);
    foreach ($list['items'] as $found) {
        \translateProduct($found['code']);
    }
} while ($list['hasMore']);
```

- **Skip what is already done** before writing, as above: a task prepared after a stop then does
  not redo, nor pay twice, what the first one did. When that is impossible (generating coupons,
  creating items without a code), tell the merchant before the launch.
- **Read before writing when the write changes the list you go through.** A script that deletes
  products while it paginates their search skips every other one: collect all the codes first,
  then process them.
- **Classify with fixed values**: give `llm_transform` an `enum[...]` and map each value to a code
  in `params.php`. A model never chooses a code.
- **A parent before its children**, a channel disabled until it is filled: order the calls, or
  the steps, so that the shop is never left in a state a customer could see half done.
