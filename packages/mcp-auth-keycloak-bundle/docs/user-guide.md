# MCP Auth Keycloak Bundle: user guide

This bundle makes Keycloak the identity provider of the MCP server.

It checks each access token with the realm.
It turns the realm roles named `mcp:...` into the grants that decide which MCP Tools the caller sees.

This guide is for the Keycloak administrator and the person who configures the deployment.

## What you can do

### Have every call checked by the realm

On each request to the MCP endpoint, the server sends the bearer token to the userinfo endpoint of the realm.

The token is accepted when:

- Keycloak answers 200: the token is genuine and still active
- the answer carries an `email` and a `sub`
- the token carries the expected audience, when one is configured

Otherwise the call is refused with a 401.

### Hand out MCP Tools with realm roles

A realm role whose name starts with `mcp:` is a grant, taken as it is.
`mcp:sylius:product:read` lets the user read products.

Any other role grants nothing.
There is no mapping from a business role to grants.
A role such as `sylius-admin` gives access only because it is a composite of `mcp:...` roles, which Keycloak expands in the token.

Widening someone's reach is therefore always an edit of the realm.

### Keep several MCP servers apart on one realm

With an audience configured, a token issued for another application of the realm is refused, even though it is genuine.

### Name the person in the audit

The caller is identified by the OIDC `sub` of the token, which is stable and opaque.
The audit records it as the actor.
The email is not used as the identifier.

### Choose the privacy mode per client

The OAuth client the token was issued to (the `azp` claim, for example `mcp-llm`) is handed to the core bundle.
The core picks the privacy mode configured for that client (`emagma_mcp_core.privacy.clients`).

## Setting it up

### Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `MCP_AUTH_USERINFO_URL` | none, required | The userinfo endpoint of the realm, as the server reaches it |
| `MCP_AUTH_AUDIENCE` | empty | The audience a token must carry |

Example:

```dotenv
MCP_AUTH_USERINFO_URL=http://keycloak:8085/realms/shop/protocol/openid-connect/userinfo
MCP_AUTH_AUDIENCE=my-mcp-server
```

`MCP_AUTH_USERINFO_URL` is the address on the server's own network, not the public one.
Its form is `<keycloak>/realms/<realm>/protocol/openid-connect/userinfo`.

`MCP_AUTH_AUDIENCE` empty accepts any genuine token of the realm.

### Configuration keys

Under `emagma_mcp_auth_keycloak`:

| Key | Default |
|---|---|
| `userinfo_url` | `%env(MCP_AUTH_USERINFO_URL)%` |
| `audience` | `%env(default::MCP_AUTH_AUDIENCE)%` |

### The firewall

The application declares the firewall that uses the bundle:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        mcp:
            pattern: ^/_mcp
            stateless: true
            entry_point: Emagma\McpAuthBundle\McpAuthenticationEntryPoint
            access_token:
                token_handler: Emagma\McpAuthKeycloakBundle\KeycloakAccessTokenHandler
```

The entry point and the metadata routes come from `emagma/mcp-auth-bundle`.

### In the realm

The bundle relies on four things:

- **Realm roles named after the grants**: one atomic role per grant, such as `mcp:sylius:product:read`
- **An audience mapper on each client meant for the server**: it adds the audience to the access token
- **`fullScopeAllowed: true` on the client**: without it the tokens carry no realm role, and the user signs in and is entitled to nothing
- **The `email` claim** available to userinfo: the `email` client scope

The developer guide lists a complete realm: roles, groups and clients.

## Who may use it

The bundle brings no tool and needs no grant.
It is what produces the grants.

## Limits and things to know

- **Signed in but no tool**: the token carries no `mcp:` role. Check the effective roles of the user and `fullScopeAllowed` on the client.
- **401 with a token that works elsewhere**: the audience. With an audience set, the client the token came from needs the audience mapper.
- **401 for a user without email**: the `email` claim is required.
- **One call to Keycloak per MCP request**: the server does not verify the signature itself and keeps no cache. Keycloak down means every call is refused.
- **A change of roles applies to the next token**: the user gets it after a refresh or a new sign-in.
- **Only realm roles count**: client roles are not read.
