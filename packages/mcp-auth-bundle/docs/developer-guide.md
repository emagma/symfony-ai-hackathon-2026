# MCP Auth Bundle: developer guide

- **Composer name**: `emagma/mcp-auth-bundle`
- **Namespace**: `Emagma\McpAuthBundle\`
- **Bundle class**: `Emagma\McpAuthBundle\EmagmaMcpAuthBundle`, an `AbstractBundle` (`configure()`, `loadExtension()`); there is no Extension nor Configuration class.
- **Config alias**: `emagma_mcp_auth`
- **Depends on**: `emagma/mcp-core-bundle` `^0.1`, Symfony Security (core and http), `symfony/yaml` for its routes file.
- **Depended on by**: the application and the bundles that validate tokens (`emagma/mcp-auth-keycloak-bundle`).
- **In the Sylius Assistant stack**: the layer is `Auth: [Core]` in `apps/mcp-server/deptrac.yaml`.

Register it after `EmagmaMcpCoreBundle`: it replaces the core's deny-all grants provider.

Classes are `final`, and `@internal` except the public API below.

## Public API

What a host and the bundles built on this one may rely on.
It follows semantic versioning.

- **`McpGrantsTokenStore`**: `store(Grants)` is how a token handler hands the grants of a validated bearer to the server.
  Injectable by class.
- **`McpAuthenticationEntryPoint`**: the host names it as the `entry_point` of its firewall.
  `METADATA_PATH` is the path of the first metadata route.
- **The routes file**: `@EmagmaMcpAuthBundle/config/routes.yaml`, with the route names `mcp_auth_protected_resource_metadata` and `mcp_auth_protected_resource_metadata_for_resource`.
- **The alias `emagma_mcp_auth`** and its three keys.
- **The parameter `emagma_mcp_auth.resource_path`**, read by the routes file.

Everything else is internal.

## How it is built

| Class | Role |
|---|---|
| `src/EmagmaMcpAuthBundle.php` | Configuration, parameters |
| `src/Controller/OAuthMetadataController.php` | Serves the RFC 9728 document |
| `src/McpAuthenticationEntryPoint.php` | The 401 with the `resource_metadata` pointer |
| `src/EventListener/McpChallengeResponseListener.php` | Adds the pointer to a 401 that lacks it |
| `src/McpGrantsTokenStore.php` | Holds the grants of the validated token for the request |
| `src/TokenGrantsProvider.php` | The core's `GrantsProviderInterface`, reading the store |
| `config/services.php` | The explicit service definitions |
| `config/routes.yaml` | The two metadata routes |

The services are named after the alias, and aliased by class where something else injects them by class:

- `emagma_mcp_auth.grants_token_store`, aliased as `McpGrantsTokenStore`, tagged `kernel.reset`.
- `emagma_mcp_auth.token_grants_provider`, which `GrantsProviderInterface` is aliased to.
- `emagma_mcp_auth.metadata_controller`, aliased as `OAuthMetadataController` (public: the routes name it by class).
- `emagma_mcp_auth.entry_point`, aliased as `McpAuthenticationEntryPoint`.
- `emagma_mcp_auth.challenge_response_listener`, on `kernel.response`.

The flow of a request to `/_mcp`:

1. **No token.**
   The firewall calls `McpAuthenticationEntryPoint::start()`.
   It answers a JSON 401 with `WWW-Authenticate: Bearer resource_metadata="<scheme+host>/.well-known/oauth-protected-resource"`.
2. **A bad token.**
   The `access_token` authenticator fails with its own 401, which has no pointer.
   `McpChallengeResponseListener` adds it to any 401 of the main request whose path starts with `resource_path` and whose header has no `resource_metadata=`.
3. **A good token.**
   The host's token handler calls `McpGrantsTokenStore::store()` with the `Grants` it derived.
   `TokenGrantsProvider::grants()` returns them to the core.
4. **End of request.**
   The store is reset (`kernel.reset`).
   Without a stored value it returns `Grants::none()`.

`OAuthMetadataController::protectedResource()` returns:

- `resource`: the request's scheme and host, then `resource_path`.
- `authorization_servers`, `scopes_supported`.
- `bearer_methods_supported: [header]`.
- the header `Access-Control-Allow-Origin: *`.

`EmagmaMcpAuthBundle::getPath()` returns the package root, so that `@EmagmaMcpAuthBundle/config/routes.yaml` resolves.

## Extension points

### Write the grants from a token handler

The bundle is identity-provider agnostic.
An adapter for another provider is an `AccessTokenHandlerInterface` that validates the bearer and stores the grants.

```php
use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpCoreBundle\Authorization\Grants;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class AcmeAccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private readonly McpGrantsTokenStore $grantsStore)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // ... validate $accessToken with the identity provider, read its entitlements
        $this->grantsStore->store(Grants::fromStrings(['mcp:sylius:product:read']));

        return new UserBadge('subject-id');
    }
}
```

To have the privacy mode follow the OAuth client, the handler also calls `Emagma\McpCoreBundle\Caller\McpCallerStore::store($clientId)`.

### What the host wires

- The firewall, with `Emagma\McpAuthBundle\McpAuthenticationEntryPoint` as `entry_point` and its token handler under `access_token.token_handler`.
- The routes: `resource: '@EmagmaMcpAuthBundle/config/routes.yaml'`.

There is no tag and no attribute.
The route names are prefixed `mcp_auth_`.

## Configuration reference

- **`authorization_servers`**
  - **Default**: `['%env(MCP_AUTH_ISSUER_URL)%']`
  - **Effect**: the parameter `emagma_mcp_auth.authorization_servers`, argument 0 of the controller; at least one, none empty
- **`resource_path`**
  - **Default**: `/_mcp`
  - **Effect**: the parameter `emagma_mcp_auth.resource_path`, argument 1 of the controller and argument 0 of the listener
- **`scopes_supported`**
  - **Default**: `[openid, profile, email]`
  - **Effect**: the parameter `emagma_mcp_auth.scopes_supported`, argument 2 of the controller

Notes:

- `resource_path` must start with `/`, otherwise the configuration is invalid.
- `config/routes.yaml` reads `emagma_mcp_auth.resource_path` for the second route (`/.well-known/oauth-protected-resource%emagma_mcp_auth.resource_path%`).
- `config/services.php` aliases `Emagma\McpCoreBundle\Authorization\GrantsProviderInterface` to the token grants provider.
- The bundle prepends nothing to other bundles' configuration.

## Before you expose the server

An MCP server built on these bundles hands a remote model the administration of a backend.
With the Sylius bundle, that means tools that refund a payment, delete a promotion or create an administrator.

This section is the overview of what guards the endpoint, and the decisions to review.
Each bundle's guide holds the detail of its own.

### Three layers

```text
MCP client --bearer--> firewall --> grants (deny-all) --> tool --> backend (service account)
                          |                |                |
                  401 + RFC 9728   tools/list filtered   human confirmation, audit
```

- **Authentication** (this bundle and a token handler).
  The host's firewall on the MCP path is stateless and has no user provider.
  The token handler (the Keycloak bundle's `KeycloakAccessTokenHandler`) validates the bearer with the identity provider.
  It stores its `mcp:*` grants in `McpGrantsTokenStore` and the OAuth client id in the core's `McpCallerStore`.
  The user is built from the token: no user table, no password, no provisioning.
  Without a token, or with a bad one, the answer is a 401 that says where to sign in.
- **Authorization** (the core bundle).
  Each tool declares its permissions.
  `tools/list` shows only what the grants allow, and a call to anything else answers as a call to an unknown tool.
  Without grants nothing is served.
  The allow-all provider is meant for local use: serving it over HTTP is logged at critical level on the `emagma_mcp_core_authorization` channel.
- **The tool** (the core bundle and the backend's bundle).
  A write asks a person before applying and is recorded in the audit.
  What the model sees of a person depends on its client.
  The backend is called with one service account, while the caller's identity stays in the server, as the actor of the audit.

### Checklist

- [ ] **The firewall** covers the MCP path, with this bundle's entry point: [The firewall](#the-firewall).
- [ ] **The audience** of the tokens is checked, so that a token issued for another application of the realm is refused: [Keycloak bundle](../../mcp-auth-keycloak-bundle/docs/user-guide.md#keep-several-mcp-servers-apart-on-one-realm).
- [ ] **The reverse proxy** is trusted, and only it: [Trusted proxies](#trusted-proxies).
- [ ] **The host check** of the HTTP transport is set on purpose: [Allowed hosts](#allowed-hosts).
- [ ] **Write confirmation**: `require_human` kept, `trusted_clients` and `auto_cancel_threshold` reviewed: [core bundle](../../mcp-core-bundle/docs/developer-guide.md#who-may-confirm-a-write).
- [ ] **Redaction**: the custom personal fields of your backend named: [core bundle](../../mcp-core-bundle/docs/developer-guide.md#personal-keys-the-redactor-does-not-know).
- [ ] **Privacy**: the mode of each OAuth client and its `reveal`: [core bundle](../../mcp-core-bundle/docs/developer-guide.md#what-the-model-sees-of-a-person).
- [ ] **Audit**: the actor, the retention applied by a scheduled job, the erasure procedure: [audit bundle](../../mcp-audit-bundle/docs/developer-guide.md#before-you-expose-the-server).
- [ ] **Sylius**: the service account and the shop's host are reviewed: [Sylius bundle](../../mcp-sylius-bundle/docs/developer-guide.md#before-you-expose-the-server).
- [ ] **Verify** from outside: [Verify](#verify).

### The firewall

- **What it does**: a bundle cannot declare a firewall, so the host does, in its `security.yaml`.
  It is a stateless firewall on the MCP path, `McpAuthenticationEntryPoint` as `entry_point`, the token handler under `access_token`, and an `access_control` rule requiring `IS_AUTHENTICATED_FULLY`.

```yaml
security:
    firewalls:
        mcp:
            pattern: ^/_mcp
            stateless: true
            entry_point: Emagma\McpAuthBundle\McpAuthenticationEntryPoint
            access_token:
                token_handler: Emagma\McpAuthKeycloakBundle\KeycloakAccessTokenHandler
    access_control:
        - { path: ^/_mcp, roles: IS_AUTHENTICATED_FULLY }
```

- **Risk**: without it, the endpoint answers without a 401 and with no grants.
  Nothing is served, but a client is never told where to sign in, and nothing proves who called.
- **What to check**: the pattern matches `resource_path`.
  The metadata routes, under `/.well-known/`, stay outside it: discovery comes before authentication.

### Trusted proxies

- **What it does**: the 401 pointer and the `resource` of the metadata are built from the request's scheme and host.
  Behind a reverse proxy, Symfony reads them from the `X-Forwarded-*` headers only when the proxy is trusted (`framework.trusted_proxies`).
  The application of the Sylius Assistant stack sets it from `TRUSTED_PROXIES` and trusts `x-forwarded-for`, `-proto`, `-host` and `-port` (not `Forwarded`).
- **Default there**: `TRUSTED_PROXIES=127.0.0.1` in the application's `.env`.
- **Risk**: an untrusted proxy makes the server advertise its internal address (often `http` and a private host name), and the client cannot follow the pointer.
  A range wider than your proxies lets anyone who reaches the server directly choose the host it advertises.
- **What to set**: the addresses or ranges of your reverse proxy (Symfony also accepts the keyword `private_ranges`).
  Avoid the keyword `REMOTE_ADDR` under a runtime that keeps the kernel booted across requests (a worker mode): it is resolved once, when the kernel boots, and then trusts that first peer only.

### Allowed hosts

- **What it does**: the HTTP transport of `mcp/sdk` protects against DNS rebinding.
  `mcp.servers.<name>.http.allowed_hosts` (symfony/mcp-bundle) lists the `Host` names accepted.
  Left unset, only `localhost`, `127.0.0.1` and `[::1]` are, and an `Origin` of another site is refused (403).
  `false` disables the protection.
- **The threat it covers**: a local server without authentication that a web page makes the victim's browser call.
- **What the Sylius Assistant stack sets**: `false`.
  The host changes with the deployment (a public domain, a tunnel, a container name for an internal client).
  The endpoint answers nothing without a bearer token, which a page cannot make a browser send.
- **What to set**: on a deployment with a fixed host, restore the protection by listing every name the server is called by, internal clients included.
  `allowed_origins` has no effect when `allowed_hosts` is `false`.

```yaml
mcp:
    servers:
        default:
            http:
                allowed_hosts: ['mcp.example.com', 'mcp.internal']
```

### Verify

From outside, with your public host:

```bash
# No token: a 401 that points to the metadata.
curl -si -X POST https://mcp.example.com/_mcp -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
# Expect: 401, and
# WWW-Authenticate: Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"
# Anything else: the firewall does not cover the endpoint.

# The metadata, public.
curl -s https://mcp.example.com/.well-known/oauth-protected-resource
# Expect: "resource": "https://mcp.example.com/_mcp" (the public scheme and host)
# and the identity provider in "authorization_servers".
```

- **The grants**: `bin/console mcp:permissions` (core bundle) lists every grant and the tools it opens.
  The identity provider must hold a role for each grant you hand out.
- **In production**: watch the `emagma_mcp_core_authorization` log channel.
  It records each refused call (a warning with the grants required) and allow-all grants served over HTTP (critical).
- **In the Sylius Assistant stack**: `castor init:verify` runs these checks on the local stack.
  `apps/mcp-server/tests/Functional/McpEndpointTest.php` pins the contract: the 401 with its pointer without a token and with a bad one, the public metadata, and a holder of `mcp:sylius:product:read` who sees exactly `get_products` and `search_products`.

## Data

None.

## Tests and quality

The bundle's tests run on their own.
`phpunit.xml.dist` has two suites:

- **`mcp-auth-unit`** (`tests/Unit`): no kernel.
- **`mcp-auth-integration`** (`tests/Integration`): a minimal kernel, `tests/Integration/Fixture/TestKernel.php`, with the core, `symfony/mcp-bundle` and `SecurityBundle`.

Unit tests:

- `tests/Unit/EmagmaMcpAuthBundleTest.php`: the configuration, the parameters, the service ids and aliases.
- `tests/Unit/McpGrantsTokenStoreTest.php`: the store and its reset.
- `tests/Unit/ProtectedResourceTest.php`: the metadata document and the challenge.

Integration tests:

- `tests/Integration/EndpointTest.php`: through a real firewall and the imported routes.
  A call without a token and with a bad one are pointed at the metadata, a good token's grants are those of the request, and the metadata is public at both addresses.

The bundle ships no `Testing/` helper.

```bash
composer install
vendor/bin/phpunit
vendor/bin/ecs check
vendor/bin/phpstan analyse
```

## Traps

- **`McpGrantsTokenStore` is a trust boundary.**
  Only a firewall token handler may write to it.
  Anything else that calls `store()` hands out tools.
- **`McpAuthenticationEntryPoint::METADATA_PATH` must match the first route of `config/routes.yaml`.**
  Change one, change the other.
- **A bundle cannot declare a firewall.**
  Forgetting it in the host leaves `/_mcp` reachable with no grants at all: nothing is served, but nothing answers 401 either.
- **`authorization_servers` and the token handler's own URL are two different addresses of the same identity provider.**
  The first is public (the client's view), the second is the server's network view.
  Do not reuse one for the other.
- **The 401 pointer uses the request's scheme and host.**
  Behind a proxy, trusted proxies must be configured or the pointer advertises the internal address (see [Trusted proxies](#trusted-proxies)).
- **Bundle order.**
  Registered before the core, the alias of `GrantsProviderInterface` would be overwritten by the core's deny-all default.
