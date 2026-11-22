# Symfony AI Hackathon 2026 Bundle: user guide

The guided tour of this stack for the Symfony AI & MCP Hackathon 2026.

It makes the chat a guide.
Ask what there is to try, and the model walks you through eight demos, one at a time.
It tells you what to type and what to look at.

It is for a newcomer to the project, a jury member, a colleague.

The bundle also carries two features the tour shows, illustrations rather than products:

- **the fraud checker agent** (demo 3): a background agent that looks at every customer account the shop creates and reports the ones whose email address looks risky,
- **`product_translate`** (demo 2): the background task that translates the products without a translation in a language.

The same tour on paper is `HACKATHON.md` at the root of the repository.
It also covers starting the stack and setting the Anthropic key.

## What you can do

### Take the tour in the chat

- **Ask**: `Guide me through the demos of the hackathon` (the chat's start screen has a button for it).
- **What comes back**: a menu of the demos, numbered from 0.
  Pick one: the model gives you the sentence to type, what to do outside the chat when there is something, and what to watch.
- **Then**: it proposes the next demo.

#### Check it worked

- **In the chat**: a `list_demos` call, then the menu.
  Pick demo 0: a `get_demo` call gives what to type, as which user, what to look at and the Symfony AI notions.
  Type `Next`: the next demo comes, up to demo 7.
- **In a terminal**: `castor mcp:console hackathon:demos` lists the eight demos, from 0 to 7.
- **Must not happen**: `catalog-manager` gets the tour.
  Only `admin` and `shop-reader` hold its grant.

### The demos

| # | Name | Shows |
|---|---|---|
| 0 | `chat` | A chat that drives the shop |
| 1 | `guidelines` | Guidelines the server hands to the model |
| 2 | `background-task` | A long task in the background |
| 3 | `fraud-agent` | An agent that works while you are away |
| 4 | `procedures` | Ask the internal procedures |
| 5 | `screens` | Screens in the conversation |
| 6 | `skills` | Skills served by the server |
| 7 | `profiles` | The same chat, another person |

The expected results of each demo, and what must not happen, are under [Check each demo](#check-each-demo).

### The MCP tools of the tour

- **`list_demos`**: the tour, each demo with the Symfony AI notions it exercises.
- **`get_demo(name)`**: one demo.
  It gives the sentences to type, the guide, the account to use, where the code is, and the next demo.

### The fraud checker agent

It only informs.
Nothing is changed in the shop, and no account is blocked.
Fixed rules decide most cases.
A model is asked only for the ambiguous ones.

The agent runs as soon as the shop says that an account was created.
It also runs every 5 minutes by default, in case a signal was lost.

Each run:

1. reads the `customer.created` events received from the shop since its last run, by batches of 50, up to 1,000 per run (the next run reads the rest),
2. reads each customer through the Admin API,
3. applies the rules below to the email address,
4. asks its model when the rules cannot settle,
5. records one report per account, whatever the conclusion, so that none is looked at twice,
6. sends an alert for the reports that reach the alert level (`high` by default, see the agents bundle).

The reports are read in the chat with `list_agent_reports`, for example "Did the fraud agent flag any account today?".

#### The rules

Each rule adds points, and the total decides.

| Rule | Points |
|---|---|
| Malformed address | High at once |
| Disposable address service | 60 |
| Domain accepts no mail | 60 |
| Local part looks generated | 30 |
| Address unrelated to the name | 10 |

- **Malformed address**: no `@`, or not a valid email address.
  Signal: "The email address is malformed."
- **Disposable address service**: the domain is in the configured list (`disposable_domains`).
  Signal: "The email domain is a disposable address service (yopmail.com)."
- **Domain accepts no mail**: the domain is not in the disposable list and its DNS has no MX, A or AAAA record.
  When the DNS cannot be checked, the rule does not apply.
  Signal: "The email domain accepts no mail (no mail server in its DNS)."
- **Local part looks generated**: the part before the `@`, reduced to its letters and digits, has at least 8 characters and one of:
  4 digits or more with at least 3 letters (`x7k2qz9p4m`), 8 letters or more with fewer than 15% vowels, 6 consonants in a row (`bcdfghjklm`).
  Signal: "The part before the @ looks randomly generated."
- **Address unrelated to the name**: the customer gave a first or last name of at least 3 letters, the letters of the local part contain neither a name nor the first 4 letters of one, and the local part has at least 5 letters.
  Accents are ignored (`José` matches `jose`).
  Signal: "The address does not relate to the name the customer gave."

#### The outcome

| Total | Outcome |
|---|---|
| 60 or more | `high`, without asking the model |
| 25 to 59 | Ambiguous: the model decides `low`, `medium` or `high` |
| Under 25 | `low`: "Nothing unusual" |

So a generated-looking local part (30, or 40 with an unrelated name) is what reaches the model.
An unrelated name alone (10) is `low`.

#### What the model is shown

- **Shown**: the shape of the address, the domain, the signals of the rules, and whether the customer gave a name (yes or no).
  The shape is the local part with every letter replaced by `a` and every digit by `9`, for example `a9a9aa9a9a@example.com`.
- **Not shown**: the address itself, the first and last name, or anything else of the customer.
- **Also given**: the risk rules, in its instructions.
- **Answers**: a level and an explanation of one or two sentences, without quoting the address.

### `product_translate`

- **What for**: prepare the task that translates the products without a translation in a locale.
- **Ask in the chat**: "Translate the products Galaxy_Ride_T_Shirt and Quasar_Bloom_T_Shirt into Italian."
  Or, for every product without an Italian translation, "Translate the catalogue into Italian."
- **Comes back**: a draft task (`taskId`, `status: draft`), its `reading` and its `manifest`.
  Nothing runs and nothing is written yet: `start_task` launches it.

| Argument | Default | Meaning |
|---|---|---|
| `locale` | required | The locale to translate into, for example `it_IT` |
| `sourceLocale` | the shop's default | The locale to translate from |
| `taxon` | all | Only the products of this taxon (its code) |
| `codes` | all | Only these products, at most 200 |

What the task does, for each product:

1. It reads the product in the source locale.
   Without a name there, it moves on.
2. It reads the product in the target locale.
   With a name there, it moves on: an existing translation is never overwritten.
3. It has the name, the description, the short description and the meta fields translated, and a slug proposed.
4. It saves the translation.
   If the shop refuses, the task stops and names the product.

The products are written one after the other, as the task goes.
There is no global validation step after the launch.

Everything about tasks themselves (launching, following, stopping, the authorization link) is in the user guide of the tasks bundle.

## Check each demo

What you should see once a demo is done, and what must not happen.

The model answers in its own words.
What counts is the MCP tool calls (the badges of each answer), the confirmation dialogs and what changes in the shop.

- **Start from**: the fixtures (`castor shop:db` reloads them), as `admin` unless said otherwise.
- **URLs and credentials**: `castor init:info`.
- **The confirmation dialog**: "Confirm the write?", with Accept, Decline and Cancel.
  It shows while the switch of the top bar reads **Confirm** (not **Auto-accepted**), and accepts on its own after 30 s.

### Demo 0: A chat that drives the shop

- **In the chat**: `List the 5 latest products` answers with a badge per model call (its tokens) and a badge per MCP tool call (its duration).
- **The write**: `Create a customer group "VIP"` opens the confirmation dialog before anything changes.
- **Decline**: the model says the write was declined, and no group is created.
- **Accept**: the group `VIP` is in the back office, Customers, Groups.
- **Must not happen**: a write without the dialog while the switch reads **Confirm**.

### Demo 1: Guidelines the server hands to the model

- **Before**: click **New conversation**.
  The server's instructions are read when a conversation opens.
- **In a terminal**: `castor mcp:console mcp:guidelines` lists six guidelines, then five intents:
  `product_description`, `website_page`, `blog_post`, `promotion`, `customer_reply`.
- **In the chat**: `Rewrite and save the short description of the product Apollo_T_Shirt` calls `get_guidelines` with `intents: ["product_description"]`.
  It returns `company` and `catalog_content`, then `upsert_products` asks for confirmation.
  Without "and save", the model may only propose the text: `Save it` then writes it.
- **Another intent**: `Write a reply to a customer whose parcel is late` calls `get_guidelines(intents: ["customer_reply"])`, which returns `company` and `customer_message`.
- **Must not happen**: a `get_guidelines` call for `Disable the product Apollo_T_Shirt` (changing a status is not writing).
  Decline the dialog to keep the product enabled.
- **Make it yours**: an intent that names an unknown guideline (`brand`) fails at `castor mcp:console cache:clear`, with an error naming `brand` as unknown.
- **No `get_guidelines` call?**
  The conversation was opened before the change: open a new one.
  The model may also skip it: the instruction says MUST, and nothing enforces it.

### Demo 2: A long task in the background, followed from the chat

The demo translates the two products of the fixtures that have real English texts: name, short description and description.
The others are filler Latin, which stays Latin once "translated".
Aurora Breeze, Nebula Glow and Orbit Pulse have real English texts too, without a short description.

- **Before**: `Do the products Galaxy_Ride_T_Shirt and Quasar_Bloom_T_Shirt have a translation in it_IT?`
  `it_IT` is missing from their `translatedLocales`.
- **Ask**: `Translate the products Galaxy_Ride_T_Shirt and Quasar_Bloom_T_Shirt into Italian`.
- **First answer**: a `product_translate` call (not `create_task`) with the two codes, a draft task and its reading, in English.
  The reading says what it will do and what stays unchanged.
- **`Launch it`**: the first time, an authorization link.
  Open it, log in as `admin`, accept: the last page says the server may act for you.
  Type `Launch it` again: the confirmation dialog, then the task is `queued`.
- **In the logs**: `castor docker:logs mcp-tasks-worker` shows the steps starting.
- **`Where is the translation task?`**: a `get_task` call.
  The status is `done`, with calls to `get_products`, `llm_transform` and `upsert_products`.
- **In Mailpit**: an email `[task · done] Translate the products into it_IT`.
- **In the back office**: Catalog, Products, each of the two products, its Italian tab filled in Italian: name, short description, description, slug.
- **On the storefront**: the main channel offers Italian.
  `http://localhost/it_IT/products/` followed by the Italian slug shows the product in Italian.
- **Nothing is overwritten**: ask and launch the same translation again.
  The task is `done`, with one `get_products` call per product and no `llm_transform`: both products are skipped.
- **Must not happen**: anything written in the shop before `Launch it` and its confirmation.
  As `catalog-manager`, a task that deletes customers (refused with `tool_not_allowed`: a task only calls its author's tools).

### Demo 3: An agent that works while you are away

After registering `x9k2qz7p4m@mailinator.com` on http://localhost/en_US/register:

- **The event arrived**: this command shows a recent `customer.created` row with the customer's id.

  ```bash
  castor mcp:console dbal:run-sql "SELECT name, subject_id, received_at FROM mcp_shop_event ORDER BY id DESC LIMIT 3"
  ```

- **In the logs**: within seconds, `castor docker:logs mcp-agents-worker` shows a `RunAgent` handled, then a `SendEmailMessage`.
- **In Mailpit**: an email `[fraud · high] customer #<id>`.
  It explains the risk and lists the signals: "The email domain is a disposable address service (mailinator.com)."
- **In the chat**: `What did the fraud agent report?` calls `list_agent_reports`.
  It shows the account at `high`, its signals, `alerted: true`.
  `Show me the last customer it reported` follows its `subject.id` with `get_customers`.
- **Run by hand**: `castor mcp:console hackathon:agent:fraud` answers `0 account(s) analysed` and sends no email: no account is analysed twice.
- **A normal account**: register `marie.durand@gmail.com`.
  It is analysed, `low`, with no email.
  `Also show the low-level analyses of the fraud agent` lists it.
- **Who reads the reports**: `shop-reader` does, and `catalog-manager` is not served the tool.
- **Must not happen**: the full address in the alert, or any change in the shop.

### Demo 4: Ask the internal procedures

- **In the chat**: `What do we do when a returned product arrives damaged?` calls `search_knowledge`.
  The answer quotes **Damaged product returned** (`returns/damaged-product.md`), its sections (for example "Tell the customer"), the team `customer-service` and the date `2026-11-10`.
- **Not covered**: `Is there a procedure for the customs duties of Switzerland?` calls `search_knowledge` too.
  The model says no procedure covers the case: it invents nothing.
- **After an edit**: add a sentence to a section of `knowledge/returns/damaged-product.md`, for example "Offer a 10 EUR voucher", and ask again.
  The sentence is quoted, without a restart.
  Put the file back with `git checkout knowledge/returns/damaged-product.md`.
- **Reindex by hand**: `castor mcp:console mcp:knowledge:index` (what changed), or with `--rebuild` (everything).
- **Must not happen**: an invented answer to the second question, or `catalog-manager` served `search_knowledge`.

### Demo 5: Screens in the conversation

- **Prices and stocks**: `Open the screen to edit the prices and stocks of the product Celestial_Harmony_T_Shirt` shows a screen in the conversation.
  There is one row per variant with its price and stock, and the channel at the top.
  The model answers in one sentence: the screen is in front of you.
- **Another channel**: pick `WEB_EUR` in the select.
  The prices are those of that channel, in its currency.
- **A wrong amount**: click **Edit prices and stocks**, type `abc` as a price, **Save**.
  The field shows an error, and nothing is saved.
- **A real amount**: type `61.00` and a new stock, **Save**, Accept in the dialog.
  The screen comes back to display with the new price and stock.
  Check it in the back office (the variant, the channel's price), or ask `Which changes were made through MCP today?` (`search_mcp_edits`).
  `Revert this change` (`revert_mcp_edit`) undoes it.
- **An address**: `Open the default address of customer 1` shows an address form (name, street, postcode, city, country).
  **Edit this address**, change the street, **Save**, Accept.
  Check it in the back office, Customers, customer 1.
- **A shipped order**: `Open the shipping address of a shipped order` shows the address read-only:
  "This order is already shipped: its shipping address can no longer be changed."
- **Must not happen**: a save without the confirmation dialog, or a model call to any screen tool but `open_screen`.

### Demo 6: Skills served by the server

- **The Skills panel**: `morning-brief`, `open-new-channel` and `prepare-task`, each with Install.
- **In the chat**: once `morning-brief` is installed, `Give me my morning brief` calls `get_skill` first.
  Then come the reads in the skill's order (orders to ship, yesterday's orders, failed payments, reviews to moderate, stock, new customers), and an answer in five lines at most.
- **The other view**: `as a report to present` gives one section per theme.
- **After an edit**: in `skills/morning-brief/SKILL.md`, ask for a sixth line "Weather today: not available", then ask again.
  The answer follows the new text.
  Put the file back with `git checkout skills/morning-brief/SKILL.md`.
- **Must not happen**: a write from the brief (it only reads).
  As `shop-reader`, `open-new-channel` or `prepare-task` in the panel (their `metadata.requires` names write grants).

### Demo 7: The same chat, another person

- **No token, no access**: in a terminal,

  ```bash
  curl -si -X POST http://localhost:8081/_mcp -H 'Content-Type: application/json' -d '{}' | head -5
  ```

  answers `HTTP/1.1 401` and `WWW-Authenticate: Bearer resource_metadata="http://localhost:8081/.well-known/oauth-protected-resource"`.
- **The grants in the token**: `castor init:verify` prints `✓ catalog-manager's token carries mcp:sylius:product:write`.
  `castor mcp:permissions` lists the grants of every tool (`create_task` needs `mcp:core:task:write`).
- **`shop-reader`**:
  - the price screen opens read-only, without **Edit prices and stocks**,
  - `Create a customer group "Test"` gets an answer that it cannot be done (no write tool is served),
  - `Open the default address of customer 1` is refused (`forbidden`: it needs `pii:reveal`),
  - the Skills panel offers `morning-brief` only.
- **`catalog-manager`**: `List the 3 latest customers` is refused: it reads the catalogue only.
- **`noaccess`**: the login works, but the server serves no tool.
  The model can neither read nor change anything in the shop.
- **Must not happen**: a write tool or a confirmation dialog for `shop-reader`.
  As `shop-reader`, `List my tasks` showing the tasks of `admin`.

## Setting it up

The bundle is registered in `apps/mcp-server/config/bundles.php`, and its demos are its own files.
Remove that line to take the tour, the fraud checker agent and `product_translate` out of a deployment.

### What the fraud checker agent needs

- The shop sends its events: the plugin `emagma/sylius-mcp-event-plugin` installed in the shop, and the events bundle on the server (see its user guide).
- The `mcp-agents-worker` service runs.
  It plays the runs of the agents (transport `mcp_agents`) and sends the alerts (transport `async`).
- `mcp-scheduler` plays the schedule of the agents (`scheduler_mcp_agents`), the safety net.
- The server's model provider has its key (`MCP_AI_ANTHROPIC_API_KEY` in this stack).
  Without it the rules still work.
- A recipient for the alerts (`MCP_AGENT_ALERT_EMAIL`, agents bundle).

### Configuration keys

Under `emagma_symfony_ai_hackathon_2026.fraud_checker_agent`:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Whether the agent runs on its own: on each new account, and on the schedule |
| `every` | `5 minutes` | How often the schedule runs it, besides the new accounts |
| `platform` | `ai.platform.anthropic` | The Symfony AI platform service of the agent |
| `model` | `claude-haiku-4-5` | The model asked for the ambiguous cases |

- **`max_output_tokens`**: `500` by default (minimum 128), the output budget of one judgement.
- **`disposable_domains`**: the list of disposable address services.
  By default `10minutemail.com`, `dispostable.com`, `guerrillamail.com`, `maildrop.cc`, `mailinator.com`, `sharklasers.com`, `temp-mail.org`, `tempmail.com`, `throwawaymail.com`, `trashmail.com` and `yopmail.com`.

```yaml
# apps/mcp-server/config/packages/emagma_symfony_ai_hackathon_2026.yaml
emagma_symfony_ai_hackathon_2026:
    fraud_checker_agent:
        every: '15 minutes'
        disposable_domains: ['yopmail.com', 'mailinator.com', 'example-throwaway.net']
```

Setting `disposable_domains` replaces the default list: repeat the entries you keep.

### The rules given to the models

These are prompts of the bundle.
They change with its code, not from the back office.

- **The risk rules** of the fraud checker agent: `resources/fraud_checker_agent/prompts/fraud_analysis.md`.
  A rule that decides an alert is reviewed like code.
- **The translation rules** of `product_translate`: `resources/product_translation/prompts/translation.md` (what is never translated, units, markup).
  They are read when the task is prepared and frozen in it.
  The guidelines of the business are not given to the task.

The translation's model is the one of the tasks bundle (`emagma_mcp_tasks.model`, `claude-haiku-4-5` by default), with the key `MCP_AI_ANTHROPIC_API_KEY`.

## Who may use it

| Feature | Grant |
|---|---|
| `list_demos`, `get_demo` | `mcp:core:demo:read` |
| `product_translate` | `mcp:sylius:product:write`, `mcp:core:task:write`, `mcp:core:llm:read` |
| Reading the agent's reports | `mcp:core:agent_report:read` (`list_agent_reports`) |

- **The tour**: the realm hands it out under the theme `hackathon` (role `hackathon-read`), part of `sylius-admin` and of `sylius-mcp-reader`.
  `admin` and `shop-reader` have it, the users of one theme do not.
- **The fraud checker agent** runs as the server, with the server's service account: no grant.
- **Launching and following the translation task** need the grants of the tasks bundle.

## Commands

| Command | What |
|---|---|
| `hackathon:demos [name]` | The tour as a table, or one demo whole |
| `hackathon:agent:fraud` | Run the fraud checker agent once, now |

```bash
castor mcp:console hackathon:demos              # the tour, as a table
castor mcp:console hackathon:demos fraud-agent  # one demo whole
castor mcp:console hackathon:agent:fraud
# [OK] 3 account(s) analysed, 1 reported (medium or high), 1 alert(s) sent.
```

`hackathon:agent:fraud` takes no argument and runs whether `enabled` is true or not.
While a run of the agent is in progress (in `mcp-agents-worker`), it says so, does nothing and exits with a failure.
Two runs of the agent never overlap.

```text
[WARNING] A pass of the fraud agent is running: it reads the new accounts. Nothing was done.
```

## Limits and things to know

### The tour

- **The tour guides, it does not check**: nothing verifies that a demo was done or worked.
- **The demos assume the fixtures**: products `Apollo_T_Shirt`, `Celestial_Harmony_T_Shirt`, `Galaxy_Ride_T_Shirt` and `Quasar_Bloom_T_Shirt`, customer 1, locale `it_IT` offered by the main channel `FASHION_WEB`.
  `castor shop:db` puts them back.
- **Demos 2 and 3 need the worker and the Anthropic key**: see `HACKATHON.md`, step 2.

### The fraud checker agent

- **It only informs.**
  No account is disabled, and nothing is written to the shop.
- **Within seconds, not at once**: a new account asks for a run.
  While one is already waiting, nothing more is asked, and that run reads every new account.
  A run reads 1,000 accounts at most: a burst of registrations beyond that is absorbed by the next runs.
- **Email address only**: no order, payment, IP address or behaviour is looked at.
- **The domain is sent to the model in clear** with the shape.
  For a personal domain that can identify someone.
- **When the model cannot be asked** (no key, provider down, unusable answer), the account is reported `medium` with the rules' signals and "the model could not be asked".
- **A customer that can no longer be read** (deleted since, or the shop did not answer) is recorded `low` so that the agent moves on.
  It is not analysed again later.
- **`medium` reports are not alerted by default**: only `high` is (`emagma_mcp_agents.alert_from`).
- **Nothing is reported?**
  Check that events arrive (events bundle) and that `mcp-agents-worker` runs.
  Then run `hackathon:agent:fraud` by hand and read `list_agent_reports` with `level: "low"`.

### `product_translate`

- **A product with a name in the target locale is skipped**, even if its description is missing.
- **The task stops at the first product the shop refuses** (a slug already taken, for instance).
  What was translated before stays.
  Prepare the task again: the products already translated are skipped, and not paid for twice.
- **One model call per product translated.**
  Products skipped cost two reads and no model call.
- **The slug is proposed by the model** from the translated name, then reduced to lowercase ASCII letters, digits and dashes.
- **Each translation is in the audit trail** and can be reverted with `revert_mcp_edit`.
