# MCP Auth Keycloak Bundle: developer guide

- **Composer name**: `emagma/mcp-auth-keycloak-bundle`
- **Namespace**: `Emagma\McpAuthKeycloakBundle`
- **Config alias**: `emagma_mcp_auth_keycloak`
- **Depends on**: `emagma/mcp-core-bundle`, `emagma/mcp-auth-bundle`, Symfony Security, `symfony/http-client-contracts`
- **Depended on by**: the application only

## How it is built

| Class | Role |
|---|---|
| `KeycloakAccessTokenHandler` | The `AccessTokenHandlerInterface` of the MCP firewall |
| `KeycloakRoles` | Reads claims from the payload of the token, without checking the signature |
| `KeycloakUser` | The security user: `sub` as identifier, `email`, role `ROLE_MCP_USER` |
| `Testing\FakeKeycloakHttpClient` | A stand-in for the userinfo endpoint, for test suites |

The services are in `config/services.php`, named `emagma_mcp_auth_keycloak.*`.
The handler is `emagma_mcp_auth_keycloak.access_token_handler`, aliased by its class for the firewall.

Symfony's `access_token` authenticator calls `KeycloakAccessTokenHandler::getUserBadgeFrom()`:

1. `GET <userinfo_url>` with the bearer. Any failure (transport, non-2xx, invalid JSON) becomes a `BadCredentialsException`.
2. `email` then `sub` must be non-empty strings in the answer, otherwise `BadCredentialsException`.
3. When an audience is configured (non-null, non-empty), it must be in the `aud` claim of the token, a string or a list.
4. `realm_access.roles` is read from the token, because userinfo does not carry it. The roles starting with `mcp:` go to `McpGrantsTokenStore::store(Grants::fromStrings(...))`.
5. The `azp` claim goes to `McpCallerStore::store()`, for the privacy mode of the core.
6. It returns a `UserBadge($sub, ...)` that loads a `KeycloakUser($sub, $email)`.

The `SecurityActorProvider` of the core then reads `getUserIdentifier()`, the `sub`, as the audit actor.

## Extension points

### What the host wires

`Emagma\McpAuthKeycloakBundle\KeycloakAccessTokenHandler` as `access_token.token_handler` of its MCP firewall.

There is no interface, attribute or tag to implement.

### Replace the HTTP client

The handler uses the service `emagma_mcp_auth_keycloak.http_client`, an alias of `http_client`.
A host can point it at a scoped client, and a test suite at the fake:

```yaml
# config/services.yaml
when@test:
    services:
        Emagma\McpAuthKeycloakBundle\Testing\FakeKeycloakHttpClient: ~
        emagma_mcp_auth_keycloak.http_client: '@Emagma\McpAuthKeycloakBundle\Testing\FakeKeycloakHttpClient'
```

### Forge a token in a test

`Testing\FakeKeycloakHttpClient` is public API, meant for the test suites of a host.
It lives in `src/Testing` and is not PHPUnit code.
It needs `symfony/http-client`, listed in `suggest`.

The fake answers 200 with `sub` and `email` for a token it forged, and 401 for anything else.

```php
use Emagma\McpAuthKeycloakBundle\Testing\FakeKeycloakHttpClient;

$token = FakeKeycloakHttpClient::token(
    'admin@example.com',
    ['mcp:sylius:product:read', 'mcp:core:audit:read'],
    clientId: 'mcp-llm',
    audiences: ['sylius-mcp'],
);
// Authorization: Bearer $token
$sub = FakeKeycloakHttpClient::subOf('admin@example.com'); // what the audit row will carry
```

The signature of `token()` is `token(string $email, array $realmRoles, string $clientId = 'mcp-llm', array $audiences = ['account']): string`.

## Configuration reference

| Key | Default | Effect |
|---|---|---|
| `userinfo_url` | `%env(MCP_AUTH_USERINFO_URL)%` | Cannot be empty |
| `audience` | `%env(default::MCP_AUTH_AUDIENCE)%` | Empty or null disables the check |

Both end up as the parameters `emagma_mcp_auth_keycloak.userinfo_url` and `emagma_mcp_auth_keycloak.audience`.

The bundle prepends nothing to the configuration of other bundles.

In a test environment, set `audience: ''` when the container exports a real `MCP_AUTH_AUDIENCE`: the fake tokens are checked on their roles, and the audience has its own unit tests.

## Data

None.

## Tests and quality

- **`EmagmaMcpAuthKeycloakBundleTest`**: alias, services, parameters, the userinfo URL from configuration or environment, empty URL rejected
- **`KeycloakAccessTokenHandlerTest`**: subject and email, `azp` stored, audience refused or not required, missing `sub`
- **`KeycloakGrantMintingTest`**: `mcp:` roles pass verbatim; other roles, and a profile role alone, grant nothing

Alone, from the package:

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

## Traps

- **`KeycloakRoles` does not check the signature.** Call it only after userinfo has vouched for the token, and for authorization only.
- **The handler is the only writer of the grants store.** Keep it so: grants must always originate from a firewall-validated token.
- **No business role maps to a wildcard**, on purpose. A profile role in a token whose composites were not expanded grants nothing.
- **Two URLs for one realm**: `MCP_AUTH_USERINFO_URL` is the backchannel (server to Keycloak), `MCP_AUTH_ISSUER_URL` (auth bundle) the public issuer.
- **A real `MCP_AUTH_AUDIENCE` in the environment leaks into tests.** Override `audience` in the test environment. A fake token carries the audience `['account']` by default.
- **The audience needs a mapper per client in the realm**, not a client scope: declaring `clientScopes` in a realm import replaces the built-in ones, and userinfo then fails.
- **`ROLE_MCP_USER` grants nothing.** Do not build access rules on it: the grants decide.

## What a realm must provide

Any realm works if it gives the bundle:

- realm roles `mcp:<domain>:<resource>:<action>`, one per grant the tools serve
- composite roles, if profiles are wanted: only `mcp:` roles grant, a business role must expand into them
- an audience mapper (`oidc-audience-mapper`, access token) on each client meant for the server
- `fullScopeAllowed: true` on those clients, so the realm roles reach the token
- the `email` scope, so that userinfo answers an email
- the same issuer for the browser and the containers (see below)

The rest of this guide describes the realm of the monorepo this bundle comes from.
It is one possible realm, set up for development and demos.
Nothing in the bundle depends on its users, groups or composite roles.

Not done, on purpose: no single sign-on for the shop admin, no admin user created per Keycloak user, no token exchange towards the shop.
The server calls the shop with one service account.
The Keycloak identity stays in the server, where it decides the grants and is the audit actor.

## The realm of the monorepo

### Generate and apply it (stack-specific)

The realm `sylius` is served by Keycloak 26.1 on `http://keycloak:8085`.

- **Source**: `.castor/keycloak-realm.php`, the only place the realm is edited: the grants (`GRANTS`), the themes (`THEMES`, `ADMIN_THEMES`), the groups, clients and users
- **`castor keycloak:realm`**: writes `docker/keycloak/realm-sylius.json` and prints a summary
- **`castor keycloak:init --recreate`**: recreates the Keycloak container, which imports the file
- **`castor keycloak:init`**: starts Keycloak, waits for the discovery of the realm, then compares the grants the tools declare (`castor mcp:permissions`) with the `mcp:` roles of the realm
- **A new grant**: add it to `GRANTS`, then run `castor keycloak:realm` and `castor keycloak:init --recreate`

Keycloak runs `start-dev --import-realm` without a data volume.
The realm is imported into a fresh container only: a plain restart keeps the running state and skips the import.

A missing role recreates the container once to import the file again.
If it is still missing, the task fails and says how to fix it.
A role no tool declares is a warning only: a grant the server does not know opens nothing.
`--no-repair` skips the recreate.

Changes made in the admin console (`http://keycloak:8085/admin`, `admin` / `admin` in the dev stack) are lost at the next recreate, including the ones `castor tunnel:up` and `castor tunnel:down` do.

### Placeholders resolved at import

The file holds no secret and no host but the local ones.
Keycloak resolves these placeholders from its own environment when it imports the realm:

| Placeholder | Used for | When unset |
|---|---|---|
| `${DEMO_USERS_PASSWORD:<username>}` | The password of every demo user | Password = username |
| `${MCP_TASKS_CLIENT_SECRET:mcp-tasks-dev-secret}` | The secret of `mcp-tasks` | The dev secret |
| `${CHAT_PUBLIC_URL:http://localhost:8090}` | Redirect and logout URIs of `mcp-testchat` | Local chat |
| `${MCP_PUBLIC_URL:http://localhost:8081}` | Redirect URI of `mcp-tasks` | Local server |

- **Who sets them**: the dev stack gives the container `MCP_TASKS_CLIENT_SECRET` (the same value as the MCP server) and `MCP_PUBLIC_URL` (the public URL of the server when set, else the tunnel, else the local one). A deployment provides `DEMO_USERS_PASSWORD`, `CHAT_PUBLIC_URL` and `MCP_PUBLIC_URL`.
- **The local URLs stay**: `mcp-testchat` and `mcp-tasks` always accept the local URL plus the public one. Unset, both entries are the same.
- **Never an empty string**: Keycloak imports an empty value as it is, and an empty password stops the import. The default applies only when the variable is unset.

### Roles

Three levels, all realm roles.

#### Atomic grants

One role per grant the tools serve, named after it: `mcp:<domain>:<resource>:<action>`, for example `mcp:sylius:product:read`.

The grants of the shop extensions are declared whether the extension is active or not.
On a shop without the extension, its roles open nothing.

#### Theme composites

`<theme>-read`, `<theme>-write` and `<theme>-delete`, each holding the grants of that action on the resources of the theme, when there are any.

On the server, a write or a delete grant also allows reading the same resource.
Write and delete stay apart.

#### Profiles

Two composites of theme roles:

- **`sylius-admin`**: read, write and delete of the admin themes, plus `audit-all` and `mcp:core:pii:reveal`
- **`sylius-mcp-reader`**: read of the admin themes. It reads everything, changes nothing, and reads only its own audit entries.

#### Themes

| Theme | Resources |
|---|---|
| `catalogue` | Products and everything attached to them, taxons |
| `configuration` | Channels, places, currencies, locales, taxes, shipping, payment |
| `marketing` | Promotions and catalog promotions |
| `sales` | Orders, payments, shipments |
| `customers` | Customers, customer groups, addresses |
| `administration` | Back-office accounts |
| `audit` | The own audit entries of the caller |
| `audit-all` | Everyone's audit entries, and the erasure of a person's data |
| `assistant` | Guidelines, background tasks, `llm_transform`, agent reports |
| `knowledge` | The internal procedures (`search_knowledge`) |
| `screens` | The screens shown in the conversation |
| `skills` | The skills served to the clients |
| `hackathon` | The guided tour (`list_demos`, `get_demo`) |

- **`catalogue` in full**: products, variants, options, attributes, images, reviews, associations and their types, taxons and their images
- **`configuration` in full**: channels, countries, provinces, zones, currencies, exchange rates, locales, tax categories and rates, shipping categories and methods, payment methods
- **Admin themes**: all of them but `administration` and `audit-all`. A new theme joins `sylius-admin` only when it is added to `ADMIN_THEMES`.
- **`administration`** is in no profile: granting the power to create admins is a separate decision.
- **`audit-all` widens the audit tools, it does not open them**: its holder also needs `audit`.

### Groups

Subgroups inherit the roles of their parents.
Every `Managers` group also carries `audit-write`: whoever writes can revert their own writes.

```text
/Administrators                          sylius-admin
/MCP/Catalog/Readers                     catalogue-read
/MCP/Catalog/Managers                    catalogue-write, audit-write
/MCP/Catalog/Managers/With Delete        + catalogue-delete
/MCP/Configuration/Readers               configuration-read
/MCP/Configuration/Managers              configuration-write, audit-write
/MCP/Configuration/Managers/With Delete  + configuration-delete
/MCP/Marketing/Readers                   marketing-read
/MCP/Marketing/Managers                  marketing-write, audit-write
/MCP/Marketing/Managers/With Delete      + marketing-delete
/MCP/Audit/Readers                       audit-read (own entries)
/MCP/Audit/Managers                      audit-read, audit-all-read, audit-all-delete
/MCP/Sales/Readers                       sales-read
/MCP/Customers/Readers                   customers-read
/MCP/Customers/Managers                  customers-write, audit-write
/MCP/Customers/Managers/With Delete      + customers-delete
/MCP/Administration/Readers              administration-read
```

- **`/MCP/Audit/Managers`** reads everyone's entries and erases the data of a person on request. It reverts nothing: a revert is a write in the shop, done by the managers of each theme.
- **No `Managers` under Sales and Administration**: their writes are granted one atomic role at a time.

### Demo users (stack-specific)

Password = username, unless the deployment sets `DEMO_USERS_PASSWORD`.
Email: `<username>@keycloak.test`.

| User | Group or role |
|---|---|
| `admin` | `/Administrators` |
| `catalog-reader` | `/MCP/Catalog/Readers` |
| `catalog-manager` | `/MCP/Catalog/Managers` |
| `catalog-admin` | `/MCP/Catalog/Managers/With Delete` |
| `config-reader` | `/MCP/Configuration/Readers` |
| `config-manager` | `/MCP/Configuration/Managers` |
| `marketing-manager` | `/MCP/Marketing/Managers` |
| `auditor` | `/MCP/Audit/Managers` |
| `sales-reader` | `/MCP/Sales/Readers` |
| `customer-reader` | `/MCP/Customers/Readers` |
| `shop-reader` | Role `sylius-mcp-reader` |
| `product-reader` | Role `mcp:sylius:product:read` alone, a one-off assignment |
| `noaccess` | Nothing: a valid identity that sees no MCP Tool |

Every imported user is also given `default-roles-sylius` explicitly.
An imported user does not get the default role of the realm.
Without its `offline_access`, Keycloak refuses the token exchange of the clients that ask for a refresh token, as Claude does.

### Clients

One OAuth client per use.
All five carry the audience mapper `mcp-server-audience` (audience `sylius-mcp`).
All but `dev-cli` set `fullScopeAllowed: true` explicitly.

#### `mcp-llm`

- **For**: the hosted connectors, Claude and ChatGPT
- **Type**: public, authorization code with PKCE S256, consent screen shown
- **Redirect URIs**: the known callbacks of the connectors (`https://claude.ai/api/mcp/auth_callback`, `https://claude.com/api/mcp/auth_callback`, `https://chatgpt.com/connector_platform_oauth_redirect`)
- **Scopes**: `offline_access` optional. Claude asks for it, and Keycloak refuses the whole request ("Invalid scopes") when a requested scope is not one of the client.
- **Registration**: the realm advertises a `registration_endpoint`, but anonymous dynamic client registration answers 403 (the default of Keycloak, kept). Give the connector the client ID `mcp-llm`.

#### `mcp-cli`

- **For**: a local MCP client on the machine of the developer (Claude Code and the like), development only
- **Type**: public, authorization code with PKCE S256, no consent screen (the user and the operator are the same person)
- **Redirect URIs**: `http://localhost/callback` and `http://127.0.0.1/callback`. Keycloak ignores the port of a loopback redirect (RFC 8252) and compares the path exactly: no wildcard needed.
- **Use**: `claude mcp add --transport http --client-id mcp-cli <name> <url>/_mcp`, or `"oauth": {"clientId": "mcp-cli"}` in a JSON client configuration

#### `mcp-testchat`

- **For**: the local test chat, development only
- **Type**: public, authorization code with PKCE S256, no consent screen
- **Redirect URIs**: `<chat URL>/oauth/callback`, for the local chat and `CHAT_PUBLIC_URL`. Logout returns to `<chat URL>/`.
- **Privacy**: in the `dev` environment, the MCP server turns off the protection of personal data for this client (`emagma_mcp_core.privacy.clients`)

#### `mcp-tasks`

- **For**: the MCP server itself, when its worker plays a background task for a user who is away
- **Type**: confidential (client secret `MCP_TASKS_CLIENT_SECRET`, held by Keycloak and the server only), consent screen shown: nobody else can make the server act for a user
- **How**: the user consents once in Keycloak and comes back to `<server URL>/tasks/authorize/callback`. The server keeps the offline token and trades it for a fresh access token, with the roles of the user at that moment, each time the worker acts.
- **Revoke**: in the account console of Keycloak, or with `revoke_task_authorization`

#### `dev-cli`

- **For**: test scripts and diagnostics
- **Type**: public, the only client with the password grant (banned by OAuth 2.1, kept in this one client). No browser flow, no redirect URI.

### Realm settings

- **Tokens**: access tokens live 5 minutes. Refresh tokens rotate (`revokeRefreshToken`, `refreshTokenMaxReuse: 0`). The SSO session idles 30 minutes, 10 hours at most.
- **Login**: brute-force protection on, registration off, "remember me" off, login with the email allowed
- **`sslRequired: none`**: plain HTTP, for local development

### The issuer must be the same everywhere

The issuer is `KC_HOSTNAME`, `http://keycloak:8085` in the dev stack.

The browser (the login pages of the chat and of the connectors) and the containers (the backchannel of the MCP server, the token requests of the chat) must reach Keycloak under that same URL:

- **Containers**: `keycloak` resolves through the compose network
- **Browser**: needs `127.0.0.1 keycloak` in `/etc/hosts`. `castor init` checks it and offers to add it.
- **Why**: the issuer in the tokens must match the one the MCP server advertises in its RFC 9728 metadata (`MCP_AUTH_ISSUER_URL`). The login cookies of Keycloak are set on the host of the form. With `localhost:8085` in the browser and `keycloak:8085` in the containers, the issuers differ and logins fail ("Restart login cookie not found").
- **A deployment** sets `KEYCLOAK_PUBLIC_URL`: it becomes the issuer, and `MCP_AUTH_ISSUER_URL` and the authorization endpoint of `mcp-tasks` follow. The backchannel (`MCP_AUTH_USERINFO_URL`, the token endpoint) stays on the internal network.

### Under a tunnel (stack-specific)

`castor tunnel:up` publishes the MCP server and Keycloak through two Cloudflare quick tunnels, so that a hosted connector can run the real OAuth flow.
It then prints the connector configuration, and `castor tunnel:info` prints it again.

- **Only the `sylius` realm is published**: the tunnel of Keycloak goes through a filter (`docker/tunnel/Caddyfile`) that lets `/realms/sylius/...` and `/resources/...` through and answers 404 to the admin console, its REST API and the master realm. The admin console keeps its local URL (`KC_HOSTNAME_ADMIN`).
- **The issuer becomes the URL of the tunnel**: `KC_HOSTNAME` and `MCP_AUTH_ISSUER_URL` switch to it. The backchannel of the server stays on `keycloak:8085`. `MCP_PUBLIC_URL` defaults to the tunnel of the server, so the `mcp-tasks` redirect follows.
- **The demo users are public while the tunnel is open**: anyone with the URL can log in as one of them (password = username) and act on the shop with the grants of that user. Close it with `castor tunnel:down`, which puts the local configuration back.
- **Both commands recreate Keycloak**: the realm is imported again from the file.
