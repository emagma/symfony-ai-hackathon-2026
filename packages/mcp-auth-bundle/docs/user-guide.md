# MCP Auth Bundle: user guide

The auth bundle makes the MCP endpoint an OAuth 2.0 protected resource.

A remote client (Claude, ChatGPT, a test chat) that connects without a token is told where to sign in.
The grants of the token it comes back with decide which MCP Tools it sees.

The bundle knows no identity provider.
Another bundle validates the tokens: `emagma/mcp-auth-keycloak-bundle` for Keycloak.

This guide is for the person who configures the deployment.

## What you can do

### Let a client discover where to sign in

The bundle serves the metadata document of RFC 9728.
It is public, at two addresses with the same content:

- `/.well-known/oauth-protected-resource`
- `/.well-known/oauth-protected-resource/_mcp`, the form some clients derive from the endpoint's URL

What it holds:

```json
{
    "resource": "https://mcp.example.com/_mcp",
    "authorization_servers": ["https://keycloak.example.com/realms/shop"],
    "scopes_supported": ["openid", "profile", "email"],
    "bearer_methods_supported": ["header"]
}
```

`resource` is built from the scheme and host of the request.

### Answer 401 with a pointer

A call to `/_mcp` without a valid token gets a `401`.
Its `WWW-Authenticate` header points at the metadata:

```text
WWW-Authenticate: Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"
```

The pointer is also added to the 401 of an invalid or expired token.

### Hand the token's grants to the server

Once the token is validated, its grants are kept for the duration of the request.
The core bundle uses them to filter the tools.

Without a validated token there are no grants: nothing is served.

## Setting it up

### Environment variable

| Variable | Purpose |
|---|---|
| `MCP_AUTH_ISSUER_URL` | The issuer, as the remote client reaches it |

Example:

```text
MCP_AUTH_ISSUER_URL=https://keycloak.example.com/realms/shop
```

### Configuration keys (`emagma_mcp_auth`)

| Key | Default |
|---|---|
| `authorization_servers` | `['%env(MCP_AUTH_ISSUER_URL)%']` |
| `resource_path` | `/_mcp` |
| `scopes_supported` | `[openid, profile, email]` |

- **`authorization_servers`**: at least one.
  Use the public URL, not the one the server uses on its own network: the client runs its discovery against it.
- **`resource_path`**: the path of the MCP endpoint, starting with `/`.
  It names the resource in the metadata, the second metadata address, and the path whose 401s get the pointer.
- **`scopes_supported`**: the scopes the metadata advertises.

```yaml
# config/packages/emagma_mcp_auth.yaml
emagma_mcp_auth:
    authorization_servers: ['https://keycloak.example.com/realms/shop']
```

### What the application declares itself

The bundle cannot declare a firewall, so the application does, in `config/packages/security.yaml`:

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

And it imports the routes, in `config/routes.yaml`:

```yaml
emagma_mcp_auth:
    resource: '@EmagmaMcpAuthBundle/config/routes.yaml'
```

Register the bundle after `EmagmaMcpCoreBundle`.
The routes are a YAML file: the application needs `symfony/yaml`, which the bundle requires.

## Who may use it

The bundle brings no tool and needs no grant.

The metadata document is public by design: discovery comes before authentication.
Everything else on `/_mcp` needs a valid token.

## Limits and things to know

- **It does not validate tokens.** That is the token handler's job.
- **It is not an authorization server.** No login page, no token endpoint, no client registration: those belong to the identity provider.
- **The client cannot sign in.**
  Open `/.well-known/oauth-protected-resource` and check that `authorization_servers` is a URL the client itself can reach.
  A container-only host name such as `http://keycloak:8085` works only for clients on the same network.
- **`resource` shows `http` behind an HTTPS proxy.**
  The scheme and host come from the request, so the proxy must be trusted (`framework.trusted_proxies`) for its forwarded headers to be honoured.
- **The metadata routes must stay outside the firewall's protected paths.**
  They are under `/.well-known/`, which the `^/_mcp` pattern does not cover.
