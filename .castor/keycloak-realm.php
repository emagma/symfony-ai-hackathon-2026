<?php

declare(strict_types=1);

/**
 * Generates docker/keycloak/realm-sylius.json - the ONLY way to edit the realm.
 *
 * Workflow: edit this file, run `castor keycloak:realm`, then
 * `castor keycloak:init --recreate` (the realm is imported on fresh containers
 * only; a plain restart keeps the live H2 state and skips the import).
 *
 * GRANTS is the list of atomic MCP grants actually served by the server's tools.
 * Refresh it from the source of truth when tools change:
 *     castor mcp:permissions --format=json
 * (from the host; or `php bin/console mcp:permissions --format=json` in
 * apps/mcp-server). The command reads every discovered tool, the shop
 * extensions' included whether MCP_SHOP_EXTENSIONS activates them or not: the
 * realm declares their grants unconditionally, since a grant no tool serves is
 * inert while a realm generated per shop would be a second source of truth.
 * Some resources lack actions - country/currency have no delete tool - never
 * guess this list.
 */

namespace keycloak;

use Castor\Attribute\AsTask;

use function Castor\io;

const REALM_FILE = __DIR__ . '/../docker/keycloak/realm-sylius.json';

const GRANTS = [
    'mcp:sylius:address:read',
    'mcp:sylius:address:write',
    'mcp:sylius:administrator:delete',
    'mcp:sylius:administrator:read',
    'mcp:sylius:administrator:write',
    'mcp:core:audit:read',
    'mcp:core:audit:write',
    'mcp:core:audit_all:delete',
    'mcp:core:audit_all:read',
    'mcp:core:audit_all:write',
    'mcp:core:demo:read',
    'mcp:sylius:catalog_promotion:delete',
    'mcp:sylius:catalog_promotion:read',
    'mcp:sylius:catalog_promotion:write',
    'mcp:sylius:channel:delete',
    'mcp:sylius:channel:read',
    'mcp:sylius:channel:write',
    'mcp:sylius:country:read',
    'mcp:sylius:country:write',
    'mcp:sylius:currency:read',
    'mcp:sylius:currency:write',
    'mcp:sylius:customer:delete',
    'mcp:sylius:customer:read',
    'mcp:sylius:customer:write',
    'mcp:sylius:customer_group:delete',
    'mcp:sylius:customer_group:read',
    'mcp:sylius:customer_group:write',
    'mcp:sylius:exchange_rate:delete',
    'mcp:sylius:exchange_rate:read',
    'mcp:sylius:exchange_rate:write',
    'mcp:sylius:locale:delete',
    'mcp:sylius:locale:read',
    'mcp:sylius:locale:write',
    'mcp:sylius:order:read',
    'mcp:sylius:order:write',
    'mcp:sylius:payment:read',
    'mcp:sylius:payment:write',
    'mcp:sylius:payment_method:delete',
    'mcp:sylius:payment_method:read',
    'mcp:sylius:payment_method:write',
    'mcp:core:agent_report:read',
    'mcp:core:guideline:read',
    'mcp:core:llm:read',
    'mcp:core:task:read',
    'mcp:core:task:write',
    'mcp:core:pii:reveal',
    'mcp:core:screen:read',
    'mcp:core:skill:read',
    'mcp:knowledge:document:read',
    'mcp:sylius:product:delete',
    'mcp:sylius:product:read',
    'mcp:sylius:product:write',
    'mcp:sylius:product_association:delete',
    'mcp:sylius:product_association:read',
    'mcp:sylius:product_association:write',
    'mcp:sylius:product_association_type:delete',
    'mcp:sylius:product_association_type:read',
    'mcp:sylius:product_association_type:write',
    'mcp:sylius:product_attribute:delete',
    'mcp:sylius:product_attribute:read',
    'mcp:sylius:product_attribute:write',
    'mcp:sylius:product_image:delete',
    'mcp:sylius:product_image:read',
    'mcp:sylius:product_image:write',
    'mcp:sylius:product_option:delete',
    'mcp:sylius:product_option:read',
    'mcp:sylius:product_option:write',
    'mcp:sylius:product_review:delete',
    'mcp:sylius:product_review:read',
    'mcp:sylius:product_review:write',
    'mcp:sylius:product_variant:delete',
    'mcp:sylius:product_variant:read',
    'mcp:sylius:product_variant:write',
    'mcp:sylius:promotion:delete',
    'mcp:sylius:promotion:read',
    'mcp:sylius:promotion:write',
    'mcp:sylius:province:read',
    'mcp:sylius:province:write',
    'mcp:sylius:shipment:read',
    'mcp:sylius:shipment:write',
    'mcp:sylius:shipping_category:delete',
    'mcp:sylius:shipping_category:read',
    'mcp:sylius:shipping_category:write',
    'mcp:sylius:shipping_method:delete',
    'mcp:sylius:shipping_method:read',
    'mcp:sylius:shipping_method:write',
    'mcp:sylius:tax_category:delete',
    'mcp:sylius:tax_category:read',
    'mcp:sylius:tax_category:write',
    'mcp:sylius:tax_rate:delete',
    'mcp:sylius:tax_rate:read',
    'mcp:sylius:tax_rate:write',
    'mcp:sylius:taxon:delete',
    'mcp:sylius:taxon:read',
    'mcp:sylius:taxon:write',
    'mcp:sylius:taxon_image:delete',
    'mcp:sylius:taxon_image:read',
    'mcp:sylius:taxon_image:write',
    'mcp:sylius:zone:delete',
    'mcp:sylius:zone:read',
    'mcp:sylius:zone:write',
];

// Thematic split mirrors the Sylius admin menu. Each theme is an isolation
// boundary: a profile granted one never sees another's resources.
//
// `sales` and `customers` are deliberately kept apart. Sales data is
// commercially sensitive; customer data is personal, and a shop may well want
// someone who can chase an unpaid order without being able to enumerate the
// customer base.
const THEMES = [
    // product_taxon is absent on purpose: the attachment tools declare
    // product + taxon permissions, so there is no such resource to grant.
    'catalogue' => ['sylius:product', 'sylius:product_association', 'sylius:product_association_type',
        'sylius:product_attribute', 'sylius:product_image', 'sylius:product_option',
        'sylius:product_review', 'sylius:product_variant', 'sylius:taxon', 'sylius:taxon_image'],
    'configuration' => ['sylius:channel', 'sylius:country', 'sylius:currency', 'sylius:exchange_rate', 'sylius:locale', 'sylius:zone',
        'sylius:province', 'sylius:tax_category', 'sylius:tax_rate', 'sylius:shipping_category',
        'sylius:shipping_method', 'sylius:payment_method'],
    // Cart and catalog promotions plus their coupons: no personal data, but
    // the power to give away margin, which is why it is its own boundary
    // rather than a corner of the catalogue.
    'marketing' => ['sylius:promotion', 'sylius:catalog_promotion'],
    // The caller's own entries (search, read, revert what they wrote)...
    'audit' => ['core:audit'],
    // ...and everyone's, the CLI's included, plus the erasure of a person's data
    // (forget_audit_personal_data). The audit_all grants widen the audit tools,
    // they do not open them: whoever holds one holds the audit grant as well.
    'audit-all' => ['core:audit_all'],
    'sales' => ['sylius:order', 'sylius:payment', 'sylius:shipment'],
    'customers' => ['sylius:customer', 'sylius:customer_group', 'sylius:address'],
    // Back-office accounts are their own boundary and belong to no bundle:
    // granting them is granting the power to mint admins, which is a different
    // decision from anything else in this file.
    'administration' => ['sylius:administrator'],
    // What the assistant itself brings, whatever the backend: the guidelines of the
    // business it hands to the model, the background tasks a user has it run (a task
    // only calls what its owner may call, with their rights of the moment), what
    // its background agents found.
    'assistant' => ['core:guideline', 'core:task', 'core:llm', 'core:agent_report'],
    // The internal procedures of the business (knowledge/*.md), searched from the
    // chat. Its own boundary: they are written for the staff, not for everyone
    // who may read the catalogue.
    'knowledge' => ['knowledge:document'],
    // The screens shown in the conversation (MCP Apps). One role opens the door,
    // screens-read; each screen then asks for the grants of the data it shows and
    // saves, so a role never sees more through a screen than through the tools.
    'screens' => ['core:screen'],
    // The skills the server hands out (list_skills, get_skill). The role opens the
    // catalogue; a skill is then listed only for who holds the grants of the
    // tools it uses.
    'skills' => ['core:skill'],
    // The guided tour of the hackathon (list_demos, get_demo).
    'hackathon' => ['core:demo'],
];

// Values Keycloak reads from its own environment when it imports the realm
// (`${NAME:default}`, resolved at import time; compose gives the container the
// variables). The file holds no secret and no host but the local ones: a stack
// that sets nothing gets exactly the local values below. Never set one of
// these variables to an empty string: Keycloak then imports an empty value
// (an empty password stops it), the default only applies when it is unset.

// Where the chat is served: local dev, plus the public URL the container is
// given (CHAT_PUBLIC_URL). Exact redirect URIs, one per origin; unset, the
// second entry repeats the first and Keycloak keeps one.
const CHAT_PUBLIC_URLS = [
    'http://localhost:8090',
    '${CHAT_PUBLIC_URL:http://localhost:8090}',
];

// Where the MCP server is served: its /tasks/authorize/callback is where a user
// comes back after letting the server run their background tasks. Local dev,
// plus the public URL the container is given (MCP_PUBLIC_URL: a tunnel, a
// public host).
const MCP_PUBLIC_URLS = [
    'http://localhost:8081',
    '${MCP_PUBLIC_URL:http://localhost:8081}',
];

// The password of every demo user: its username unless the container is given
// DEMO_USERS_PASSWORD (one value for all of them).
const DEMO_USERS_PASSWORD = 'DEMO_USERS_PASSWORD';

// The secret of the confidential `mcp-tasks` client, the one the MCP server
// reads (MCP_TASKS_CLIENT_SECRET); the dev stack's value by default.
const MCP_TASKS_SECRET = '${MCP_TASKS_CLIENT_SECRET:mcp-tasks-dev-secret}';

// What the tokens meant for the MCP server carry in `aud`: the server refuses a
// token of the realm issued for another resource (MCP_AUTH_AUDIENCE). A mapper on
// each client, not a client scope: declaring `clientScopes` in an import replaces
// the realm's built-in ones (profile, email, roles), and userinfo then fails.
const MCP_AUDIENCE = 'sylius-mcp';
const MCP_AUDIENCE_MAPPER = [
    'name' => 'mcp-server-audience',
    'protocol' => 'openid-connect',
    'protocolMapper' => 'oidc-audience-mapper',
    'config' => [
        'included.custom.audience' => MCP_AUDIENCE,
        'access.token.claim' => 'true',
        'id.token.claim' => 'false',
    ],
];

// Themes a "full Sylius admin" carries implicitly - the ones whose menus that
// role already opens in the admin UI: catalogue, configuration, marketing,
// orders and customers, plus the audit.
//
// `sales` and `customers` were deliberately excluded at first (PII reach
// growing by accident); the decision was reversed on 2026-09-14 so that a full
// admin over MCP matches a full admin in the UI. This list is still an explicit
// choice: a NEW theme does not join it unless someone adds it here.
// `administration` (minting admins) stays out.
const ADMIN_THEMES = ['catalogue', 'configuration', 'marketing', 'sales', 'customers', 'audit', 'assistant', 'knowledge', 'screens', 'skills', 'hackathon'];

// Carried by sylius-admin, not by sylius-mcp-reader: reading everyone's audit
// entries is the auditor's, not a read-only profile's, which reads its own.
const ADMIN_ONLY_THEMES = ['audit-all'];

const ACTIONS = ['read', 'write', 'delete'];

#[AsTask(name: 'realm', description: 'Regenerate docker/keycloak/realm-sylius.json from .castor/keycloak-realm.php (then keycloak:init --recreate)')]
function realm(): void
{
    $realm = realm_definition();

    file_put_contents(REALM_FILE, realm_json($realm));

    $composites = 0;
    foreach (array_keys(THEMES) as $theme) {
        foreach (ACTIONS as $action) {
            $composites += null !== realm_composite($theme, $action) ? 1 : 0;
        }
    }

    io()->writeln(\sprintf(
        '%s: %d atomic grants, %d composites, %d users, %d clients',
        basename(REALM_FILE),
        \count(GRANTS),
        $composites,
        \count($realm['users']),
        \count($realm['clients']),
    ));
}

/**
 * The JSON in the format realm-sylius.json has always had: two-space
 * indentation, slashes and non-ASCII characters as they are, a final newline.
 *
 * @param array<string, mixed> $realm
 */
function realm_json(array $realm): string
{
    $json = json_encode($realm, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

    // json_encode indents by four spaces; a JSON string never spans lines, so
    // only indentation starts a line with spaces.
    return preg_replace_callback(
        '/^((?: {4})+)/m',
        static fn (array $m): string => str_repeat(' ', \intdiv(\strlen($m[1]), 2)),
        $json,
    ) . "\n";
}

/**
 * @return list<string>
 */
function realm_theme_grants(string $theme, string $action): array
{
    // A grant is mcp:<domain>:<resource>:<action>; a theme lists <domain>:<resource>.
    return array_values(array_filter(GRANTS, static function (string $grant) use ($theme, $action): bool {
        $parts = explode(':', $grant);

        return \in_array($parts[1] . ':' . $parts[2], THEMES[$theme], true) && $parts[3] === $action;
    }));
}

/**
 * @return array<string, mixed>|null
 */
function realm_composite(string $theme, string $action): ?array
{
    $members = realm_theme_grants($theme, $action);
    if ([] === $members) {
        return null;
    }
    $implication = [
        'read' => 'read tools',
        'write' => 'write tools (write implies read, NOT delete)',
        'delete' => 'delete tools (delete implies read, NOT write)',
    ][$action];

    return [
        'name' => "$theme-$action",
        'description' => "Theme: $theme $implication",
        'composite' => true,
        'composites' => ['realm' => $members],
    ];
}

/**
 * Demo user; password = username, unless the container is given
 * DEMO_USERS_PASSWORD (see the constant).
 *
 * @param list<string>      $roles
 * @param list<string>|null $groups
 *
 * @return array<string, mixed>
 */
function realm_user(string $username, string $first, string $last, array $roles = [], ?array $groups = null): array
{
    $entry = [
        'username' => $username,
        'enabled' => true,
        'emailVerified' => true,
        'email' => "$username@keycloak.test",
        'firstName' => $first,
        'lastName' => $last,
        'credentials' => [['type' => 'password', 'value' => '${' . DEMO_USERS_PASSWORD . ':' . $username . '}', 'temporary' => false]],
    ];
    // Imported users do NOT receive the realm default role (`default-roles-sylius`:
    // offline_access, uma_authorization, account scopes) the way users created
    // through the UI/API do. Remote MCP clients (Claude) request `offline_access`
    // for refresh tokens, and Keycloak refuses the code-to-token exchange for a
    // user lacking that role - so assign the default role explicitly.
    $entry['realmRoles'] = [...$roles, 'default-roles-sylius'];
    if (null !== $groups) {
        $entry['groups'] = $groups;
    }

    return $entry;
}

/**
 * @return array<string, mixed>
 */
function realm_definition(): array
{
    $adminAndOnly = [...ADMIN_THEMES, ...ADMIN_ONLY_THEMES];

    $adminComposites = [];
    foreach ($adminAndOnly as $theme) {
        foreach (['write', 'delete'] as $action) {
            if (null !== realm_composite($theme, $action)) {
                $adminComposites[] = "$theme-$action";
            }
        }
    }
    // Read as well: a theme may have a resource that is only read (the
    // guidelines), which no write grant implies.
    foreach ($adminAndOnly as $theme) {
        if (null !== realm_composite($theme, 'read')) {
            $adminComposites[] = "$theme-read";
        }
    }
    // Orthogonal to the themes: the `reveal` argument of the get_* tools under
    // a pseudonymising client (P10.4).
    $adminComposites[] = 'mcp:core:pii:reveal';

    $readerComposites = [];
    foreach (ADMIN_THEMES as $theme) {
        if (null !== realm_composite($theme, 'read')) {
            $readerComposites[] = "$theme-read";
        }
    }

    $themeComposites = [];
    foreach (array_keys(THEMES) as $theme) {
        foreach (ACTIONS as $action) {
            $composite = realm_composite($theme, $action);
            if (null !== $composite) {
                $themeComposites[] = $composite;
            }
        }
    }

    return [
        'realm' => 'sylius',
        'enabled' => true,
        // Dev runs plain http (localhost + compose DNS).
        'sslRequired' => 'none',
        'loginWithEmailAllowed' => true,
        'duplicateEmailsAllowed' => false,
        'registrationAllowed' => false,
        'rememberMe' => false,
        // OAuth 2.1 hygiene: short access tokens, rotated refresh tokens,
        // brute-force lockout.
        'bruteForceProtected' => true,
        'accessTokenLifespan' => 300,
        'ssoSessionIdleTimeout' => 1800,
        'ssoSessionMaxLifespan' => 36000,
        'revokeRefreshToken' => true,
        'refreshTokenMaxReuse' => 0,
        'roles' => [
            'realm' => [
                // Composite rather than a wildcard: Keycloak expands it into the
                // individual mcp:* realm roles, so the handler needs no special
                // case and a new theme cannot join it silently.
                [
                    'name' => 'sylius-admin',
                    'description' => 'Full Sylius admin: every MCP grant on ' . implode(', ', ADMIN_THEMES),
                    'composite' => true,
                    'composites' => ['realm' => $adminComposites],
                ],
                [
                    'name' => 'sylius-mcp-reader',
                    'description' => 'MCP read-only on ' . implode(', ', ADMIN_THEMES),
                    'composite' => true,
                    'composites' => ['realm' => $readerComposites],
                ],
                ...array_map(static function (string $grant): array {
                    $parts = explode(':', $grant);

                    return ['name' => $grant, 'description' => "MCP grant: {$parts[3]} on {$parts[2]} ({$parts[1]})"];
                }, GRANTS),
                ...$themeComposites,
            ],
        ],
        // Subgroups INHERIT parent roles (e.g. Managers/With Delete adds
        // catalogue-delete on top of the inherited catalogue-write). Every Managers
        // group of a theme also carries audit-write: whoever writes reverts their
        // own writes.
        'groups' => [
            ['name' => 'Administrators', 'realmRoles' => ['sylius-admin']],
            ['name' => 'MCP', 'subGroups' => [
                ['name' => 'Catalog', 'subGroups' => [
                    ['name' => 'Readers', 'realmRoles' => ['catalogue-read']],
                    ['name' => 'Managers', 'realmRoles' => ['catalogue-write', 'audit-write'], 'subGroups' => [
                        ['name' => 'With Delete', 'realmRoles' => ['catalogue-delete']],
                    ]],
                ]],
                ['name' => 'Configuration', 'subGroups' => [
                    ['name' => 'Readers', 'realmRoles' => ['configuration-read']],
                    ['name' => 'Managers', 'realmRoles' => ['configuration-write', 'audit-write'], 'subGroups' => [
                        ['name' => 'With Delete', 'realmRoles' => ['configuration-delete']],
                    ]],
                ]],
                ['name' => 'Marketing', 'subGroups' => [
                    ['name' => 'Readers', 'realmRoles' => ['marketing-read']],
                    ['name' => 'Managers', 'realmRoles' => ['marketing-write', 'audit-write'], 'subGroups' => [
                        ['name' => 'With Delete', 'realmRoles' => ['marketing-delete']],
                    ]],
                ]],
                // Readers read their own entries. Managers read everyone's and erase a
                // person's data on request, and revert nothing: a revert is a write
                // of the shop, the managers of each theme revert their own.
                ['name' => 'Audit', 'subGroups' => [
                    ['name' => 'Readers', 'realmRoles' => ['audit-read']],
                    ['name' => 'Managers', 'realmRoles' => ['audit-read', 'audit-all-read', 'audit-all-delete']],
                ]],
                // Operational themes, granted to nobody by default: no group
                // inherits them from higher up the tree, and sylius-admin does
                // not carry them either. They all have writes now - order/payment
                // state actions, customer CRUD, back-office accounts - which is
                // exactly why they stay opt-in.
                ['name' => 'Sales', 'subGroups' => [
                    ['name' => 'Readers', 'realmRoles' => ['sales-read']],
                ]],
                ['name' => 'Customers', 'subGroups' => [
                    ['name' => 'Readers', 'realmRoles' => ['customers-read']],
                    ['name' => 'Managers', 'realmRoles' => ['customers-write', 'audit-write'], 'subGroups' => [
                        ['name' => 'With Delete', 'realmRoles' => ['customers-delete']],
                    ]],
                ]],
                // No Managers subgroup on purpose: creating back-office accounts is
                // granted one atomic role at a time, never by dropping someone in a
                // group.
                ['name' => 'Administration', 'subGroups' => [
                    ['name' => 'Readers', 'realmRoles' => ['administration-read']],
                ]],
            ]],
        ],
        'clients' => [
            // Pre-registered public client for LLM MCP connectors (Claude, ChatGPT).
            // Public + PKCE S256 (OAuth 2.1), user consent shown. Redirect URIs are
            // the publicly known connector callbacks - extend as needed. Connectors
            // that insist on DCR use the realm registration_endpoint instead.
            [
                'clientId' => 'mcp-llm',
                'name' => 'MCP LLM connectors (Claude / ChatGPT)',
                'enabled' => true,
                // Explicit: the import left this client at false, which strips every
                // realm role (composites, group roles) from its tokens - and the MCP
                // handler derives ALL grants from those roles, so the connector would
                // authenticate fine and then be entitled to nothing.
                'fullScopeAllowed' => true,
                'protocol' => 'openid-connect',
                'publicClient' => true,
                'standardFlowEnabled' => true,
                'implicitFlowEnabled' => false,
                'directAccessGrantsEnabled' => false,
                'serviceAccountsEnabled' => false,
                'consentRequired' => true,
                'redirectUris' => [
                    'https://claude.ai/api/mcp/auth_callback',
                    'https://claude.com/api/mcp/auth_callback',
                    'https://chatgpt.com/connector_platform_oauth_redirect',
                ],
                'webOrigins' => [],
                'attributes' => ['pkce.code.challenge.method' => 'S256'],
                'defaultClientScopes' => ['web-origins', 'profile', 'roles', 'email'],
                'protocolMappers' => [MCP_AUDIENCE_MAPPER],
                // Claude asks for `openid profile email offline_access`; Keycloak
                // rejects the WHOLE authorization request ("Invalid scopes") when any
                // requested scope is not assigned to the client, so offline_access
                // has to be at least optional here.
                'optionalClientScopes' => ['offline_access'],
            ],
            // Dev-only: the local MCP client (Claude Code and friends) talking to
            // /_mcp on this machine. Separate from mcp-llm on purpose -- that one
            // is the pre-registered CLOUD connectors, whose callbacks are fixed
            // public URLs; a local CLI listens on an ephemeral loopback port, which
            // is a different trust story and deserves its own client.
            //
            // No wildcard, deliberately. The CLI picks a free port per flow, so the
            // redirect cannot be pinned -- but Keycloak follows RFC 8252 and ignores
            // the PORT when matching a loopback redirect, while still comparing the
            // path exactly. So a bare `http://localhost/callback` matches
            // `http://localhost:53187/callback` and nothing else.
            //
            // `http://localhost:*/callback` does NOT work (Keycloak only accepts `*`
            // as the final character), and `http://localhost/*` does work but is far
            // too wide -- it would also accept `http://localhost/admin`, i.e. the
            // shop itself, as a place to deliver an authorization code.
            //
            // No consent screen: the resource owner and the operator are the same
            // person here, and a prompt on every reconnect would train the reflex to
            // click through. mcp-llm keeps its consent for exactly the opposite
            // reason.
            //
            // Discovery reaches this client the RFC 9728 way: /_mcp answers 401 with
            // resource_metadata, which names this realm as the authorization server.
            // Anonymous DCR (RFC 7591) stays OFF -- registration_endpoint is
            // advertised but returns 403, which is the Keycloak default and worth
            // keeping. The client is named instead: `"oauth": {"clientId":
            // "mcp-cli"}` in .mcp.json, `--client-id mcp-cli` on `claude mcp add`.
            //
            // fullScopeAllowed explicitly, for the reason given on mcp-llm. And
            // offline_access optional, as on mcp-llm: Claude Code adds it to the
            // scopes of the protected resource whenever the realm advertises it,
            // and Keycloak refuses the whole request ("Invalid scopes") when a
            // requested scope is not the client's.
            [
                'clientId' => 'mcp-cli',
                'name' => 'MCP local CLI (Claude Code) -- dev only',
                'enabled' => true,
                'fullScopeAllowed' => true,
                'protocol' => 'openid-connect',
                'publicClient' => true,
                'standardFlowEnabled' => true,
                'implicitFlowEnabled' => false,
                'directAccessGrantsEnabled' => false,
                'serviceAccountsEnabled' => false,
                'consentRequired' => false,
                'redirectUris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
                'webOrigins' => [],
                'attributes' => ['pkce.code.challenge.method' => 'S256'],
                'defaultClientScopes' => ['web-origins', 'profile', 'roles', 'email'],
                'optionalClientScopes' => ['offline_access'],
                'protocolMappers' => [MCP_AUDIENCE_MAPPER],
            ],
            // Dev-only: the local test chat (apps/chat, compose.chat.yaml)
            // logging its users in the way a hosted MCP connector does --
            // authorization code + PKCE -- so the tokens it sends to /_mcp carry
            // the logged-in user's roles, exactly as Claude Web's would.
            //
            // Its own client rather than mcp-cli: a web app on a fixed port is not
            // a CLI on an ephemeral loopback port, and each client here maps to one
            // trust story. Exact redirect URI, no wildcard.
            //
            // fullScopeAllowed explicitly, for the reason given on mcp-llm: without
            // it the import strips every realm role from the tokens and the user is
            // entitled to nothing. No consent screen: operator and user are the same
            // developer, as with mcp-cli.
            [
                'clientId' => 'mcp-testchat',
                'name' => 'MCP local test chat (apps/chat) -- dev only',
                'enabled' => true,
                'fullScopeAllowed' => true,
                'protocol' => 'openid-connect',
                'publicClient' => true,
                'standardFlowEnabled' => true,
                'implicitFlowEnabled' => false,
                'directAccessGrantsEnabled' => false,
                'serviceAccountsEnabled' => false,
                'consentRequired' => false,
                'redirectUris' => array_map(static fn (string $url): string => "$url/oauth/callback", CHAT_PUBLIC_URLS),
                'webOrigins' => [],
                'attributes' => [
                    'pkce.code.challenge.method' => 'S256',
                    'post.logout.redirect.uris' => implode('##', array_map(static fn (string $url): string => "$url/", CHAT_PUBLIC_URLS)),
                ],
                'defaultClientScopes' => ['web-origins', 'profile', 'roles', 'email'],
                'protocolMappers' => [MCP_AUDIENCE_MAPPER],
            ],
            // The MCP server itself, when its worker runs a background task for a user
            // who is away (packages/mcp-tasks-bundle): the user consents ONCE to the
            // offline access, the server keeps the offline token and trades it for a
            // fresh access token, with the user's roles of the moment, each time the
            // worker acts. Confidential: only the server holds the secret
            // (MCP_TASKS_CLIENT_SECRET, given to Keycloak and to the server alike;
            // the dev stack's value when unset, see MCP_TASKS_SECRET).
            // The consent screen is the point: nobody else can make the server act
            // for a user. Revoked by the user in the account console, or with
            // revoke_task_authorization.
            [
                'clientId' => 'mcp-tasks',
                'name' => 'MCP background tasks (the server acting for a user)',
                'enabled' => true,
                'fullScopeAllowed' => true,
                'protocol' => 'openid-connect',
                'publicClient' => false,
                'clientAuthenticatorType' => 'client-secret',
                'secret' => MCP_TASKS_SECRET,
                'standardFlowEnabled' => true,
                'implicitFlowEnabled' => false,
                'directAccessGrantsEnabled' => false,
                'serviceAccountsEnabled' => false,
                'consentRequired' => true,
                'redirectUris' => array_map(static fn (string $url): string => "$url/tasks/authorize/callback", MCP_PUBLIC_URLS),
                'webOrigins' => [],
                'defaultClientScopes' => ['web-origins', 'profile', 'roles', 'email'],
                'optionalClientScopes' => ['offline_access'],
                'protocolMappers' => [MCP_AUDIENCE_MAPPER],
            ],
            // Dev-only: quarantines the password grant (banned by OAuth 2.1) for
            // the curl verification scripts. No browser flow, no redirect.
            [
                'clientId' => 'dev-cli',
                'name' => 'Dev CLI (password grant for test scripts ONLY)',
                'enabled' => true,
                'protocol' => 'openid-connect',
                'publicClient' => true,
                'standardFlowEnabled' => false,
                'implicitFlowEnabled' => false,
                'directAccessGrantsEnabled' => true,
                'serviceAccountsEnabled' => false,
                'redirectUris' => [],
                'webOrigins' => [],
                'defaultClientScopes' => ['web-origins', 'profile', 'roles', 'email'],
                'protocolMappers' => [MCP_AUDIENCE_MAPPER],
            ],
        ],
        'users' => [
            realm_user('admin', 'Sylius', 'Administrator', groups: ['/Administrators']),
            realm_user('catalog-reader', 'Catalog', 'Reader', groups: ['/MCP/Catalog/Readers']),
            realm_user('catalog-manager', 'Catalog', 'Manager', groups: ['/MCP/Catalog/Managers']),
            realm_user('catalog-admin', 'Catalog', 'Admin', groups: ['/MCP/Catalog/Managers/With Delete']),
            realm_user('config-reader', 'Config', 'Reader', groups: ['/MCP/Configuration/Readers']),
            realm_user('config-manager', 'Config', 'Manager', groups: ['/MCP/Configuration/Managers']),
            realm_user('marketing-manager', 'Marketing', 'Manager', groups: ['/MCP/Marketing/Managers']),
            realm_user('auditor', 'Audit', 'Manager', groups: ['/MCP/Audit/Managers']),
            realm_user('sales-reader', 'Sales', 'Reader', groups: ['/MCP/Sales/Readers']),
            realm_user('customer-reader', 'Customer', 'Reader', groups: ['/MCP/Customers/Readers']),
            // Reads everything, changes nothing: screens open read-only, and only the
            // skills that need nothing but reads are listed.
            realm_user('shop-reader', 'Shop', 'Reader', roles: ['sylius-mcp-reader']),
            // Direct atomic grant - the one-off assignment pattern.
            realm_user('product-reader', 'Product', 'Reader', roles: ['mcp:sylius:product:read']),
            // Valid identity, zero entitlement → 401 (least-privilege control).
            realm_user('noaccess', 'No', 'Access', roles: []),
        ],
    ];
}
