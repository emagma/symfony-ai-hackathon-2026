# MCP Core Bundle: user guide

The core bundle is what every other bundle of an MCP server stands on.
It brings no MCP Tool of its own.

It decides which tools a caller sees.
It makes a person approve every write.
It hides personal identifiers from the model, and scrubs sensitive values before anything is stored.

This guide is for the person who configures the deployment, and for the administrator of the identity provider.

## What you can do

### Decide who sees which MCP Tool

- **What it does**: every tool declares the grants it needs. A caller without them does not see the tool in the list, and a call to it answers exactly like a call to a tool that does not exist.
- **Default**: nobody is allowed anything. Tools appear only once an authentication bundle hands the caller's grants over.
- **Resources and prompts**: served to nobody under real grants, with one exception: the `ui://` document of an MCP App (a screen shown in the conversation) is served to whoever is served one of the tools linked to it.

### Make a person approve every write

A tool that changes something asks before applying.
What happens depends on the client:

| The client | What happens |
|---|---|
| Supports MCP elicitation | A prompt shows the preview; the person accepts or refuses |
| Has no elicitation, is trusted | The tool returns the preview; a second call with `confirm: true` applies |
| Has no elicitation, is not trusted | The tool refuses: `human_confirmation_required` |

When the tool does not apply, `reason` says why:

| `reason` | Meaning |
|---|---|
| `declined` | The person refused in the prompt |
| `cancelled` | The person closed the prompt |
| `elicitation_unavailable_preview_only` | Nothing applied; call again with `confirm: true` |
| `human_confirmation_required` | This client cannot approve a write at all |

### Keep personal identifiers away from the model

Under the `pseudonymise` mode, the default:

- an email, a phone number or a login is replaced by a stable token, for example `email:7f3a9c21`;
- data the model does not need to act (a birthday, a gender) is left out of the result;
- names and free text pass unchanged.

The same value always gives the same token, so the model can still tell that two orders belong to the same person.

The mode is chosen per OAuth client (the `azp` claim of the access token).
A model the merchant hosts can be served in clear (`off`) while a cloud assistant is not.

### Mark text written by outsiders

Fields typed by customers or visitors (a review, an order note) are named in an `untrustedContent` key of the result.
The result carries once a notice that tells the model to treat them as data, never as instructions.

### Scrub what is stored

Before a write is kept in the audit or in a trace, secrets and personal data are replaced by a marker (`__REDACTED__` by default):

- any key containing `secret`, `password`, `passphrase`, `apikey`, `api_key`, `credential`, `privatekey` or `private_key`;
- the personal keys each bundle declares for its backend, plus the ones you add;
- any email address found inside a remaining text value.

Tool results and confirmation previews keep the real values: the scrubbing applies to what is persisted only.

## Setting it up

Install the bundle and register it after `symfony/mcp-bundle`:

```bash
composer require emagma/mcp-core-bundle
```

The configuration lives under `emagma_mcp_core` (`config/packages/emagma_mcp_core.yaml`):

| Key | Default | Purpose |
|---|---|---|
| `server` | `default` | Which server of `mcp.servers` the bundle guards |
| `permission_domains` | none | Filled by the bundles; rarely set by hand |
| `untrusted_content_notice` | built-in text | The notice about `untrustedContent` |

### `write_confirmation`

| Key | Default | Purpose |
|---|---|---|
| `require_human` | `[write, delete]` | Actions a model may never confirm alone |
| `trusted_clients` | `[]` | Client names whose own UI asks the person |
| `auto_cancel_threshold` | `0.0` | Seconds; see below |

- **`trusted_clients`**: the `clientInfo.name` a client sends when it connects. List only clients whose own interface makes a person approve each tool call.
- **`auto_cancel_threshold`**: some clients declare elicitation and cancel it at once where they cannot show it. A cancel that comes back faster than this many seconds is taken as the client's, not a person's, and the session is handled as one without elicitation. `0` disables the detection: every cancel is a refusal.

### `redaction`

| Key | Default | Purpose |
|---|---|---|
| `marker` | `__REDACTED__` | What replaces a scrubbed value |
| `personal_keys` | `[]` | Extra key names (exact, any case) holding personal data |
| `secret_fragments` | `[]` | Extra substrings marking a key as a secret |

### `privacy`

| Key | Default | Purpose |
|---|---|---|
| `mode` | `pseudonymise` | Mode of a client not listed: `pseudonymise` or `off` |
| `secret` | the application secret | Keys the tokens; `MCP_PRIVACY_SECRET` overrides it |
| `clients` | none | Per OAuth client id: `mode`, and `reveal` (default `true`) |

A client that must never obtain values in clear, even for a caller holding the reveal grant:

```yaml
emagma_mcp_core:
    privacy:
        clients:
            mcp-llm: { mode: pseudonymise, reveal: false }
```

### Environment variable

| Variable | Default | Purpose |
|---|---|---|
| `MCP_PRIVACY_SECRET` | the application secret | Rotates the tokens without touching `APP_SECRET` |

### In the Sylius Assistant stack

`apps/mcp-server/config/packages/emagma_mcp_core.yaml` sets:

```yaml
emagma_mcp_core:
    server: sylius
    write_confirmation:
        trusted_clients: ['Anthropic/ClaudeAI', 'openai-mcp', 'claude-code']
        auto_cancel_threshold: 2.0
    redaction:
        marker: '__REDACTED_BY_SYLIUS_ASSISTANT__'

when@dev:
    emagma_mcp_core:
        privacy:
            clients:
                mcp-testchat: off
```

## Who may use it

A grant is a string `mcp:<domain>:<resource>:<action>`, for example `mcp:sylius:product:write`.

- **Domain**: the business solution a bundle covers (`sylius`, `core`, `knowledge`), not the server.
- **Actions**: `read`, `write`, `delete`, `reveal`.
- **Implications**: `write` gives `read`; `delete` gives `read`; `write` and `delete` are independent; `reveal` is given by nothing else.
- **Several permissions on one tool**: the caller needs all of them.

Wildcards use the word `all`:

| Grant | Covers |
|---|---|
| `mcp:sylius:all:read` | Reading every resource of the `sylius` domain |
| `mcp:all:all:write` | Writing (and reading) everything |
| `mcp:all:product:read` | Nothing: a resource name across every domain is not accepted |

The bundle itself defines one grant:

| Grant | Purpose |
|---|---|
| `mcp:core:pii:reveal` | Lets `reveal: true` on a `get_*` tool of people return values in clear |

How a caller gets grants depends on your identity provider; see the auth bundles' guides.

### In the Sylius Assistant stack

In the realm generated by `.castor/keycloak-realm.php`, `mcp:core:pii:reveal` is an atomic realm role carried by the composite role `sylius-admin`.
It belongs to no theme.

## Commands

### `mcp:permissions`

- **Run**: `bin/console mcp:permissions` (`castor mcp:permissions` in the Sylius Assistant stack).
- **What it shows**: per `<domain>:<resource>`, the tools behind each action and the grant strings to hand out, then the wildcards and the implications.
- **Option**: `--format=json` prints `grants`, `wildcards` and `undeclared` for scripts (`txt` is the default).
- **Exit code**: fails when a tool declares no permission, or belongs to a namespace no bundle gave a domain to. Such a tool can be authorised by no grant.

## Limits and things to know

- **No tool at all in the client**: the caller holds no grant that fits, or no authentication bundle is installed (the default is deny-all). Run `mcp:permissions` and compare with the caller's roles.
- **A refused tool looks like a missing one**: on purpose. The refusal is in the server's log, channel `emagma_mcp_core_authorization` ("MCP tool call denied by authorization", with the grants required).
- **Do not change `redaction.marker` on an audit that already holds entries**: older scrubbed entries would no longer be recognised as scrubbed.
- **Changing the privacy secret changes every token**: a token the model saw before no longer matches.
- **An empty privacy secret stops the server** on the first MCP request: set `APP_SECRET` or `MCP_PRIVACY_SECRET`.
- **`trusted_clients` rests on the client's own interface**: for these clients the model's `confirm: true` is accepted. List a client only when its UI really asks the person.
- **Emptying `require_human`** lets the model confirm writes on its own word on every client without elicitation. Meant only for a deployment with no person in the loop by design.
- **Redaction works on key names**: a personal value under a key nobody declared is kept, unless it is an email address. Phone numbers are not detected by pattern.
- **Allow-all grants** (authorization disabled, for stdio and local development) served over HTTP are reported once per process at critical level in the log.
