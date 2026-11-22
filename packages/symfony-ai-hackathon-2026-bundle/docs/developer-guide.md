# Symfony AI Hackathon 2026 Bundle: developer guide

- **Composer name**: `emagma/symfony-ai-hackathon-2026-bundle`
- **Namespace**: `Emagma\SymfonyAiHackathon2026Bundle`
- **Config alias**: `emagma_symfony_ai_hackathon_2026`
- **Depends on**: `emagma/mcp-core-bundle`, `emagma/mcp-sylius-bundle`, `emagma/mcp-sylius-events-bundle`, `emagma/mcp-agents-bundle`, `emagma/mcp-tasks-bundle`, `symfony/ai-agent`, `symfony/ai-platform`.
- **Needs from the host**: a Symfony AI platform service (`ai.platform.anthropic` by default) for the fraud agent, and `framework.lock` for the trigger.

Nothing depends on this bundle.

It holds three things:

- the tour: the demos, served to the chat,
- the fraud checker agent (`FraudCheckerAgent\`): a background agent built with Symfony AI, demo 3,
- the product translation (`ProductTranslation\`): `product_translate`, a ready-made background task, demo 2.

The last two are illustrations, kept with the tour that presents them.
What a real deployment keeps lives in the other bundles.

## Public API

There is none to build on: the bundle is an end of the graph.
What other code may rely on:

- the tool names `list_demos`, `get_demo` and `product_translate`,
- the command names `hackathon:demos` and `hackathon:agent:fraud`,
- the agent name `fraud`, which is the key of its journal,
- the configuration `emagma_symfony_ai_hackathon_2026`.

Everything else is `@internal`.

## How it is built

### The tour

| Class or file | Role |
|---|---|
| `demos/<position>-<name>.md` | One demo: a YAML front matter, then the guide as Markdown |
| `DemoRepository` | Reads the directory once, sorted by position: `all()`, `get()`, `after()` |
| `Demo` | The value object |
| `Tool/ListDemos`, `Tool/GetDemo` | The two MCP tools |
| `Command/DemosCommand` | `hackathon:demos [name]` |

The descriptions of the two tools carry the guiding behaviour:

- one demo at a time,
- quote the sentence for the user to send,
- do not run it unless they ask.

### The bundle class

`EmagmaSymfonyAiHackathon2026Bundle` does four things:

- it defines the configuration (the `fraud_checker_agent` keys),
- it prepends the two tool namespaces to the core's server, and the permission domains,
- it sets parameters from the configuration (`emagma_symfony_ai_hackathon_2026.demos_directory`, `.fraud.*`),
- it gives the fraud judge the platform service the configuration names.

`config/services.php` holds the definitions, ids prefixed by the alias.
The tools are the exception: `Tool/` is loaded by namespace, `ProductTranslate` is defined by hand and autoconfigured.

### The fraud checker agent

| Class | Role |
|---|---|
| `FraudCheckerAgent\FraudAgent` | One run: events, customers, rules, judge, report, alert |
| `FraudCheckerAgent\EmailRiskRules` | The rules in code, a score and signals |
| `FraudCheckerAgent\RiskAssessment` | Score, signals, shape, domain; `HIGH` is 60, `AMBIGUOUS` is 25 |
| `FraudCheckerAgent\DnsDomainChecker` | `checkdnsrr` on MX, A and AAAA |
| `FraudCheckerAgent\AgentRiskJudge` | The Symfony AI agent for the ambiguous cases |
| `EventListener\NewAccountListener` | `customer.created` asks `AgentRunner::trigger('fraud')` |
| `Command\RunFraudAgentCommand` | `hackathon:agent:fraud`: one run, by hand |
| `resources/fraud_checker_agent/prompts/fraud_analysis.md` | The risk rules, added to the system prompt |

`FraudAgent` implements `ScheduledAgentInterface` of the agents bundle.
Its `name()` is `fraud`.
Its `every()` is the configured interval, or null when `enabled` is false.
It is tagged `emagma_mcp_agents.agent`.

#### What wakes it

- **`NewAccountListener`**: tagged `kernel.event_listener` on `ShopEventRecorded`.
  When the event is `customer.created` and `enabled` is true, it calls `AgentRunner::trigger('fraud')`.
  A pass already waiting: nothing more is queued.
  A lock or a transport that fails is logged as a warning, never thrown: the event is in the journal, the next pass reads it.
- **The schedule of the agents**: every `every()`, the safety net.
- **`hackathon:agent:fraud`**: `AgentRunner::run('fraud')`, under the agent's lock.
  While a pass runs, it prints a warning and fails.

This bundle is what binds the two: the events bundle knows no agent, the agents bundle no shop event.

#### One run

A loop of batches, from a cursor of its own.
The cursor starts at `lastSequence('fraud')`, then moves to the last event of each batch, so that a batch already recorded still moves it on.
The loop ends when a batch comes back shorter than asked, or when `maxPerPass` events were read (`FraudAgent::MAX_PER_PASS`, 1,000).

Per batch:

1. `ShopEventStoreInterface::after('customer.created', $cursor, 50)`.
   No event: the run ends.
2. The customers are read in one `AdminApiClientInterface::getDecodedMany()` call.
3. Each event is analysed:
   - no readable email: `Level::Low`, "The customer could not be read",
   - `EmailRiskRules::assess()`,
   - high: `Level::High` with the signals,
   - not ambiguous: `Level::Low`,
   - otherwise `RiskJudgeInterface::judge()`, and a `JudgementFailedException` is logged and reported as `Level::Medium`.
4. `AgentReport::of('fraud', ...)` is recorded.
   An already recorded source is skipped.
5. `AgentAlerter::alert()`, and one `save()` at the end of the batch.

The run returns `{analysed, reported, alerted}`.
`reported` counts medium and high.

#### The rules

The address is trimmed and lower-cased.
Malformed (no `@`, or `FILTER_VALIDATE_EMAIL` fails): high at once.
Otherwise:

| Check | Score |
|---|---|
| Domain in `disposable_domains` | +60 |
| Else the domain accepts no mail | +60 |
| The local part looks generated | +30 |
| The local part is unrelated to the name | +10 |

- **Looks generated**: the local part reduced to `[a-z0-9]`, at least 8 characters, and one of:
  4 digits or more with 3 letters or more, or 8 letters or more with a vowel ratio under 0.15, or 6 consecutive consonants.
- **Unrelated to the name**: names transliterated to ASCII, reduced to letters, kept from 3 letters.
  False when none is kept, or when the local part contains a name or its first 4 letters.
  Otherwise true when the local part has 5 letters or more.
- **Shape**: letters become `a`, digits become `9`.
  The assessment's shape is the shape of the local part, then `@`, then the domain.
- A `null` from the domain checker (cannot tell) adds nothing.

#### The judge

`AgentRiskJudge::judge()` builds a `Symfony\AI\Agent\Agent` on the configured platform and model.
It has one input processor: `SystemPromptInputProcessor`, with the instructions (`SYSTEM_PROMPT`) followed by the risk rules (`RULES`).

The user message is JSON: `shape`, `domain`, `signals`, `hasName`.
`parse()` strips a code fence and requires a `level` and a non-empty `explanation`.
Anything else throws `JudgementFailedException`.

### The product translation

| Class or file | Role |
|---|---|
| `ProductTranslation\Tool\ProductTranslate` | The MCP tool `product_translate` |
| `ProductTranslation\TranslationTask` | The params and the script of the task |
| `resources/product_translation/task/translate.php` | The script, played by the worker |
| `resources/product_translation/prompts/translation.md` | The translation rules |

It is the example of a ready-made task: a tool that calls `TaskManager::create()` with a script it ships, instead of a model writing one.

`ProductTranslate::__invoke()`:

1. Refuses without an actor, with twice the same locale, with more than 200 codes, or with a locale the shop does not have.
2. `TranslationTask::params()` writes `params.php`: `SOURCE_LOCALE`, `TARGET_LOCALE`, `TAXON`, `CODES`, `FIELDS` and `INSTRUCTION`.
   The instruction holds the translation rules, read now.
3. `TaskManager::create()` with one step, `translate`.
   The task is a draft, and the answer is the one of `create_task`.

The script only uses `call()`:

- `search_products`, or the codes given,
- `get_products` in the source locale,
- `llm_transform`,
- `upsert_products` in `update` mode, with the target locale on the item.

It skips a product without a source name, or whose `translatedLocales` already holds the target locale.
It throws when the slug is empty or when the shop refuses the write.

## Extension points

### Adding a demo

Create `demos/09-my-demo.md`.
The file name gives the position and the name.
Positions run from 0 without a gap.

```markdown
---
title: What the demo shows, in a few words
user: admin
prompts:
  - 'The first sentence the user types'
  - 'The second one'
symfony_ai:
  - 'The Symfony AI notion it exercises, with the class or attribute'
code:
  - packages/my-bundle
---
What to do outside the chat, if anything, then:

- **Look at** what proves it worked.
```

| Key | Required | Meaning |
|---|---|---|
| `title` | yes | Shown in the menu |
| `user` | no | The demo account, `admin` by default |
| `prompts` | no | The sentences typed in the chat, in order |
| `symfony_ai` | yes (test) | The notions exercised |
| `code` | yes (test) | Paths from the repository root, which must exist |

Then add the demo to `HACKATHON.md` and to the user guide: they are written by hand.

### `RiskJudgeInterface`

Aliased to `AgentRiskJudge`.
Replace the alias to settle the ambiguous cases another way.

```php
use Emagma\McpAgentsBundle\Level;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\Judgement;
use Emagma\SymfonyAiHackathon2026Bundle\Exception\JudgementFailedException;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\RiskAssessment;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\RiskJudgeInterface;

final readonly class CautiousJudge implements RiskJudgeInterface
{
    public function judge(RiskAssessment $assessment, bool $hasName): Judgement
    {
        if ([] === $assessment->signals) {
            throw new JudgementFailedException('Nothing to judge.');
        }

        return new Judgement(
            $hasName ? Level::Low : Level::Medium,
            'Generated-looking address' . ($hasName ? ', but a name was given.' : ' and no name.'),
        );
    }
}
```

### `DomainCheckerInterface`

Aliased to `DnsDomainChecker`.
Tests replace it so that no DNS is queried.

```php
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\DomainCheckerInterface;

final readonly class NoDnsChecker implements DomainCheckerInterface
{
    /** true: has a mail server; false: has none; null: cannot be told. */
    public function acceptsMail(string $domain): ?bool
    {
        return null;
    }
}
```

### The prompts

- **The risk rules**: `resources/fraud_checker_agent/prompts/fraud_analysis.md`, read by `AgentRiskJudge::systemPrompt()`.
- **The translation rules**: `resources/product_translation/prompts/translation.md`.
  The task is given no guidelines of the business: that is for later.

Both change with the code of the bundle.

### Changing what is translated

`TranslationTask::FIELDS` lists the fields read in the source locale and asked of the model.
The script is a file of the bundle and is not configurable: a variant is another tool with its own script.

## Configuration reference

Under `emagma_symfony_ai_hackathon_2026.fraud_checker_agent`:

| Key | Default | Constraint |
|---|---|---|
| `enabled` | `true` | Boolean |
| `every` | `5 minutes` | Not empty |
| `platform` | `ai.platform.anthropic` | Not empty |
| `model` | `claude-haiku-4-5` | Not empty |

- **`max_output_tokens`**: `500`, minimum 128.
- **`disposable_domains`**: a list of strings, by default `EmagmaSymfonyAiHackathon2026Bundle::DISPOSABLE_DOMAINS` (11 domains).

What the bundle does with them:

- `disposable_domains` is an argument of `EmailRiskRules`.
- `platform` (a service reference), `model` and `max_output_tokens` are arguments of `AgentRiskJudge`.
- `every` is an argument of `FraudAgent`, null when `enabled` is false.
- `enabled` is also an argument of `NewAccountListener`.

The product translation has no key.
Its `$defaultLocale` is the parameter `emagma_mcp_sylius.default_locale`.

What the bundle prepends:

| Target | What |
|---|---|
| `emagma_mcp_core.permission_domains` | `Emagma\SymfonyAiHackathon2026Bundle\` to `core` |
| `emagma_mcp_core.permission_domains` | `Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation\` to `sylius` |
| The server's `registry.tools` | `Tool\` and `ProductTranslation\Tool\` |

The longest prefix wins.
`product_translate` has `mcp:sylius:product:write`, `mcp:core:task:write` and `mcp:core:llm:read`.
`list_demos` and `get_demo` have `mcp:core:demo:read`.

## Data

No entity, table or migration of its own.

- **The tour**: the files of `demos/`, nothing written.
- **The fraud checker agent** reads `mcp_shop_event` through `ShopEventStoreInterface` and writes `mcp_agent_report` through `AgentReportStoreInterface`.
  It writes `agent = 'fraud'`, `source` the event id, `source_sequence` the event's sequence, `subject_type = 'customer'`.
  Its cursor is the highest `source_sequence` of its own reports.
- **The product translation** writes a task of the tasks bundle.

## Tests and quality

The tests call no model and no DNS.

- **`tests/Integration/WiringTest`**: boots a kernel with the core and the MCP server, the other bundles' services declared synthetic.
  The container is compiled, so every reference of `config/services.php` is checked.
  It proves the three tools are on the server, `product_translate` keeps its three grants, the demos stay in `core`, and the configuration reaches the parameters.
- **`tests/Unit/DemosTest`**: positions without a gap, every demo names a Symfony AI notion, the menu and the next demo, the command, a file without a title.
  Every `code` path must exist in the repository: that test is skipped when the package is checked on its own.
- **`tests/Unit/FraudCheckerAgent/`**:
  - `EmailRiskRulesTest`: addresses that are high, ambiguous or fine, signals and shape never carry the address, a domain that cannot be checked is not held against the account.
  - `AgentRiskJudgeTest`: the answer is a level and an explanation, anything else is refused, the prompt asks for no personal data.
  - `FraudAgentTest`: every new account is analysed once, only the ambiguous ones reach the model, a customer that can no longer be read is recorded, a run stops at its bound and the next one catches up.
  - `NewAccountListenerTest`: `customer.created` triggers `fraud`, another event or `enabled: false` triggers nothing.
  - `RunFraudAgentCommandTest`: the summary, and the refusal while the agent's lock is held.
- **`tests/Unit/ProductTranslation/ProductTranslateTest`**: the tool prepares a draft and writes nothing.
  Then the script is played in the real hardened process of the tasks bundle, against a shop in memory.

Run them on their own from the package directory:

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

## Traps

### The tour

- **A broken demo file stops both tools**: `DemoRepository` throws on a missing front matter or title. The test catches it first.
- **An apostrophe in a quoted YAML string is doubled**: `'the server''s own provider'`.
- **The tour is written three times**: the demo files, `HACKATHON.md`, the user guide. Nothing keeps them in step.

### The fraud checker agent

- **Never put the address or the name in a signal, the shape or the prompt.**
  The signals are stored, emailed and listed by `list_agent_reports`, and a test guards it.
  The one exception is the domain: the disposable-domain signal names it, and `shape` and `domain` send it to the model.
- **Every event must end in a recorded report**, even for an unreadable customer: the cursor is the highest recorded sequence.
  A customer that could not be read because the shop was down is recorded `low` and never analysed again.
- **The name `fraud` is the agent's journal**: renaming it restarts the agent from the first event.
- **The agent reads the shop with the server's service account**, as an administrator would.
  A token bound to a narrower profile is the planned next step.
- **`looksGenerated` operator precedence**: the first two conditions are `$digits >= 4 && strlen($letters) >= 3 || (...)`. Keep the grouping when editing.
- **`DnsDomainChecker` silences `checkdnsrr` errors and treats "no record" as false**: a resolver failure that answers "no record" counts as 60 points. It returns null only when the function does not exist.
- **`enabled: false` takes the agent off the schedule**, and `hackathon:agent:fraud` ignores it.
- **A run handles 50 events at a time**, and the next run takes the following ones.
- **`AgentRiskJudge` builds a new `Agent` at each call** and is the only place a model is asked: high and low cases never reach it.

### The product translation

- **The script is inspected like any other**: no `eval`, `include` or `echo`, and only the functions the tasks bundle allows.
  The coding standard does not check it: it is not under `src/`.
- **The params are generated**: every value goes through `TranslationTask::literal()`, so that a taxon code holding a quote cannot leave its string.
- **The rules are frozen at creation**, in `INSTRUCTION`: they are part of what the human validates, and of what `get_task(detail: "scripts")` shows.
- **`get_products` answers the product even without a translation in the locale**: its texts then fall back to another translation. Only `translatedLocales` says whether the target locale has one.
- **`upsert_products` takes the locale per item** (`items[].locale`): a top-level `locale` is ignored and the write goes to the shop's default locale.
- **Its permission domain is `sylius`**, not the bundle's `core`: the bundle prepends the longer prefix. A tool moved out of `ProductTranslation\` would change domain, hence grant.
