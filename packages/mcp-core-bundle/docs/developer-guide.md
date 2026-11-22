# MCP Core Bundle: developer guide

- **Composer name**: `emagma/mcp-core-bundle`
- **Namespace**: `Emagma\McpCoreBundle\`
- **Bundle class**: `Emagma\McpCoreBundle\EmagmaMcpCoreBundle`, an `AbstractBundle` (`configure()`, `prependExtension()`, `loadExtension()`, `build()`); there is no Extension nor Configuration class.
- **Config alias**: `emagma_mcp_core`
- **Depends on**: `mcp/sdk` `^0.9`, `symfony/mcp-bundle` `^0.15`, `symfony/security-bundle`. No other emagma package.
- **Suggested**: `phpunit/phpunit` and `symfony/uid`, for the test cases and helpers of `src/Testing`.
- **Depended on by**: every bundle that brings tools to the server, and the application.
- **In the Sylius Assistant stack**: the layer is `Core: ~` in `apps/mcp-server/deptrac.yaml`; every other layer may know it.

Register it after `McpBundle`: its compiler passes reach the server's builder once `McpBundle` has set it up.

Classes are `final`.
The wiring is `@internal`: the compiler passes, the listeners, the command, the registry decorator and the `tools/call` handler.
Everything else is the public API a bundle author builds on, listed in the next section.

## Public API

What a bundle built on the core uses:

| What | Where |
|---|---|
| Prepend to the core | `DependencyInjection\McpCoreConfig` |
| Declare what a tool needs | `#[Permission]`, `#[WidenedBy]`, `#[RevealsPersonalData]`, `#[Deprecated]`, `#[Irreversible]` |
| Extend through a tag | `ToolGateInterface`, `ToolCallMiddlewareInterface`, `SensitiveKeysProviderInterface`, `McpInstructionInterface` |
| Replace by an alias | `GrantsProviderInterface`, `ActorProviderInterface`, `WriteJournalInterface`, `RevertTargetInterface`, `SubjectReferencesInterface` |
| Inject | `ToolPermissionResolver`, `ToolClassMap`, `LocalToolCaller`, `McpCallerStore`, `SensitiveDataRedactor` |
| Use in a tool | `WriteConfirmationTrait`, `DiffPreview`, `OutputFieldsTrait` and the field marks, `PageRequest`, `PrivacyContext` |
| Test a bundle | `Testing\Conformance\*TestCase`, `ElicitationTestTrait`, `SinkWriteJournal` |

### Prepend to the core: `McpCoreConfig`

A bundle that brings tools calls it from its own prepend step, with the `ContainerBuilder`.
Each method prepends, so the host's own configuration overrides it.
Each does nothing when the bundle it configures is not registered.

```php
namespace Emagma\McpCoreBundle\DependencyInjection;

final class McpCoreConfig
{
    public const string ALIAS = 'emagma_mcp_core';

    /** Adds to the `mcp.servers` entry of the server the core attaches to. */
    public static function prependServerConfig(ContainerBuilder $container, array $config): void;

    /** The domain of the grants of the tools under a namespace prefix; the longest prefix wins. */
    public static function prependPermissionDomain(ContainerBuilder $container, string $namespace, string $domain): void;

    /** What the model is told about the fields marked #[Untrusted]. */
    public static function prependUntrustedContentNotice(ContainerBuilder $container, string $notice): void;
}
```

The server is `default` unless the host's `emagma_mcp_core.server` names another.

In an `AbstractBundle`:

```php
use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;

public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
{
    McpCoreConfig::prependPermissionDomain($builder, 'Acme\\McpThingsBundle\\', 'things');
    McpCoreConfig::prependServerConfig($builder, ['registry' => ['tools' => ['Acme\\McpThingsBundle\\Tool\\']]]);
}
```

In an `Extension` implementing `PrependExtensionInterface`, the same calls go in `prepend(ContainerBuilder $container)`.

### Declare what a tool needs

```php
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(name: 'echo', description: 'Returns the text it was given.')]
#[Permission(resource: 'echo', action: Action::Read)]
final class EchoTool
{
    public function __invoke(string $text): string
    {
        return $text;
    }
}
```

- **`#[Permission(resource, action)]`**: repeatable, with AND semantics. A tool without it is never authorised under real grants. The domain comes from the namespace (`prependPermissionDomain()`).
- **`#[WidenedBy(resource, action)]`**: repeatable. A permission the tool checks itself to serve more than its `#[Permission]` gives (the audit's `audit_all`: every entry instead of the caller's own). Not required to call the tool; `mcp:permissions` lists it, so that the identity provider mints its role.
- **`#[RevealsPersonalData]`**: the tool has a `reveal` argument. It is not a permission: the tool stays readable without the grant and checks `RevealsPersonalData::permission()` (`core:pii:reveal`) itself.
- **`#[Deprecated(replacedBy: 'new_tool')]`**: hidden from `tools/list`, still served by `tools/call`.
- **`#[Irreversible]`**: a write tool no snapshot written back undoes (a state transition, a file replaced, an email sent). A tool declaring a `delete` permission is irreversible without it (`Irreversible::declaredBy()`). The audit records it on the entry and refuses the revert before anything is read.

`Action` is `read`, `write`, `delete` or `reveal`.
`Grants` is the value object of a caller's grants (`fromStrings()`, `allows()`, `allowsAll()`).

### Extend through a tag

| Tag | Interface | Order |
|---|---|---|
| `emagma_mcp_core.tool_gate` | `Authorization\ToolGateInterface` | `priority`, the first refusal wins |
| `emagma_mcp_core.tool_call_middleware` | `Call\ToolCallMiddlewareInterface` | `priority`, the highest is the outermost |
| `emagma_mcp_core.sensitive_keys` | `Redaction\SensitiveKeysProviderInterface` | none |
| `emagma_mcp_core.instruction` | `Instruction\McpInstructionInterface` | `priority`, the highest comes first |

`#[AsMcpInstruction(priority: 100)]` on a class tags it `emagma_mcp_core.instruction` by autoconfiguration.
The other tags are set explicitly in the bundle's services: no interface is autoconfigured.

#### A gate: refuse a tool on this deployment

A gate answers the same for every caller; what depends on the caller belongs to the grants.

```php
use Emagma\McpCoreBundle\Authorization\HandlerClass;
use Emagma\McpCoreBundle\Authorization\ToolGateInterface;
use Emagma\McpCoreBundle\Authorization\ToolRefusal;
use Mcp\Capability\Registry\ToolReference;

final readonly class MaintenanceGate implements ToolGateInterface
{
    public function refusal(ToolReference $reference): ?ToolRefusal
    {
        return 'echo' === $reference->tool->name
            ? new ToolRefusal(['ok' => false, 'reason' => 'maintenance'], 'MCP tool call during maintenance.')
            : null;
    }
}
```

`HandlerClass::of($reference->handler)` gives the tool's class, to read an attribute of its own.

#### A middleware: wrap every call

```php
use Emagma\McpCoreBundle\Call\ToolCallMiddlewareInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\SessionInterface;

final class TimingMiddleware implements ToolCallMiddlewareInterface
{
    public function handle(CallToolRequest $request, SessionInterface $session, callable $next): Response|Error
    {
        $started = hrtime(true);
        $result = $next($request, $session);
        // ... record (hrtime(true) - $started) for $request->name

        return $result;
    }
}
```

A tool that elicits suspends a Fiber inside `$next`.
A middleware that must see each suspension runs `$next` in a Fiber of its own.

#### Sensitive keys

```php
use Emagma\McpCoreBundle\Redaction\SensitiveKeysProviderInterface;

final class ThingSensitiveKeys implements SensitiveKeysProviderInterface
{
    public function personalKeys(): array
    {
        return ['email', 'firstName', 'lastName'];
    }

    public function opaqueSubtrees(): array
    {
        return ['gateway' => ['config']];
    }
}
```

#### An instruction

```php
use Emagma\McpCoreBundle\Instruction\AsMcpInstruction;
use Emagma\McpCoreBundle\Instruction\McpInstructionInterface;

#[AsMcpInstruction(priority: 100)]
final class ThingsInstruction implements McpInstructionInterface
{
    public function text(): string
    {
        return 'Every amount is in minor units.';
    }
}
```

| Priority | Who |
|---|---|
| > 0 | What the server is: a backend's conventions |
| 0 | The business; the `instructions` of the server's configuration |
| < 0 | What must be read last |

To change an order, change the priority of the tag.
To change a text, decorate the service.
`LiteralInstruction` holds a fixed text, for an instruction declared in configuration.

### Replace by an alias

| Interface | Default | Replaced by |
|---|---|---|
| `Authorization\GrantsProviderInterface` | deny-all | an auth bundle, or the host |
| `Caller\ActorProviderInterface` | the security user | the host, for another notion of actor |
| `Journal\WriteJournalInterface` | records nothing | the audit bundle |
| `Journal\RevertTargetInterface` | none | a backend bundle |
| `Journal\SubjectReferencesInterface` | none | a backend bundle |

Alias the interface to your service.
The core's own implementations keep a class alias, so that a host can pick one: `DenyAllGrantsProvider`, `AllowAllGrantsProvider`, `SecurityActorProvider`, `NullActorProvider`, `NullWriteJournal`.

```php
use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;

final readonly class StaticGrantsProvider implements GrantsProviderInterface
{
    public function grants(): Grants
    {
        return Grants::fromStrings(['mcp:things:all:read']);
    }
}
```

`RevertTargetInterface` and `SubjectReferencesInterface` are read by the audit bundle; its developer guide shows how.

### Inject

| Class | Gives |
|---|---|
| `Authorization\ToolPermissionResolver` | The permissions of a tool class, in their domain |
| `Call\ToolClassMap` | The class of a tool by its name, and whether it is irreversible |
| `Call\LocalToolCaller` | Calls a tool by name from a worker or a command, with the grants of whoever the process acts as |
| `Caller\McpCallerStore` | The OAuth client of the request, written by the host's token handler |
| `Redaction\SensitiveDataRedactor` | Scrubs a payload, a diff or a text before it is stored |

Each is a service named after the alias (`emagma_mcp_core.permission_resolver`, `.tool_class_map`, `.local_tool_caller`, `.caller_store`, `.redactor`) with an alias of its class: autowiring finds it.

`LocalToolCaller::call()` throws `LocalToolCallFailed` when the tool is unknown or forbidden, when the arguments do not match its schema, or when it fails.
There is nobody to elicit: `confirm` is set, because the caller of this class answers for the validation.

### Use in a tool

#### Confirm and record a write

```php
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Mcp\Server\RequestContext;

#[Permission(resource: 'thing', action: Action::Write)]
final class RenameThing
{
    use WriteConfirmationTrait;

    public function __construct(private readonly WriteJournalInterface $journal)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $code, string $name, bool $confirm = false, ?RequestContext $context = null): array
    {
        $before = ['code' => $code, 'name' => 'Old'];   // read from the backend
        $diff = ['name' => ['before' => 'Old', 'after' => $name]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm the change of ' . $code,
            'Apply it?',
            DiffPreview::transitions('Rename ' . $code, $diff),
            'Re-invoke with confirm: true to apply.',
        );
        if (null !== $refusal) {
            return $refusal + ['diff' => $diff];
        }

        $after = ['code' => $code, 'name' => $name];    // returned by the backend
        $this->journal->record('rename_thing', 'thing', $code, $before, $after, $diff, $context);

        return ['ok' => true, 'updated' => true, 'diff' => $diff];
    }
}
```

- `confirmWrite()` returns `null` to proceed, or the refusal to merge into the tool's answer.
- `DiffPreview` renders what the person reads: `targets()` for a creation, `transitions()` for an update, `format()` for one value.
- A batch tool calls `stage()` per item with one `$batchId`, then `flush()` once.
- The target code is recorded as given: an id, never personal data.

#### Mark an output

```php
use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpCoreBundle\Output\Surplus;
use Emagma\McpCoreBundle\Output\Untrusted;

final readonly class CustomerOutput implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
        public string $code,
        #[Untrusted, Personal, Identifier]
        public ?string $email,
        #[Untrusted]
        public ?string $comment,
        #[Personal, Surplus]
        public ?string $birthday,
    ) {
    }
}
```

- **`#[Untrusted]`**: written by a member of the public; named in `untrustedContent`.
- **`#[Personal]`**: personal data; its key is redacted before anything is stored.
- **`#[Identifier]`**: an email, a phone, a login; a token under `pseudonymise`.
- **`#[Surplus]`**: data the model does not need; absent under `pseudonymise`.

`FieldRegistry::of()` and `with()` read the marks of a class.
`PrivacyContext::hides()` tells whether the current response pseudonymises; `revealAllowed()` and `revealThisResponse()` serve a `reveal: true`.

#### Page a search

`PageRequest::of($page, $itemsPerPage)` clamps to 1..50, 30 by default.
`slice()` and `offset()` cut the list; `envelope()` gives `{items, total, page, hasMore}`.

### Test a bundle: `src/Testing`

The core ships, for the test suites of the bundles that bring tools:

- **`Testing\Conformance\ToolPermissionCoverageTestCase`**: every `#[McpTool]` declares a permission.
- **`Testing\Conformance\ToolAnnotationsCoverageTestCase`**: the annotations agree with the permissions.
- **`Testing\Conformance\MutatingToolsAreGatedTestCase`**: a class calling `confirmWrite(` declares `write` or `delete`.
- **`Testing\Conformance\IrreversibleToolsTestCase`**: a tool named with one of the bundle's `transitionVerbs()`, or whose description says IRREVERSIBLE, is `#[Irreversible]`; only a write tool is.
- **`Testing\ElicitationTestTrait`**: `noElicitContext()`, `elicitingContext()`, and `runWithElicitation()`, which runs the tool in a Fiber and answers the elicitation by hand.
- **`Testing\SinkWriteJournal`**, with `WriteSinkInterface` and `RecordedWrite`: a journal whose sink is a mock, so that a bundle tests its write tools without the audit bundle. A write recorded with an email as target code throws `\LogicException`.

They need PHPUnit, which the core only suggests; `ElicitationTestTrait` also needs `symfony/uid`.
A bundle extends the conformance cases in its own `tests/Unit/Conformance`:

```php
use Emagma\McpCoreBundle\Testing\Conformance\ToolPermissionCoverageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ToolPermissionCoverageTest extends ToolPermissionCoverageTestCase
{
    protected static function toolClasses(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3) . '/src/Tool/*.php') ?: [] as $file) {
            $class = 'Acme\\McpThingsBundle\\Tool\\' . basename($file, '.php');
            if (class_exists($class)) {
                yield $class;
            }
        }
    }

    protected static function minimumChecked(): int
    {
        return 3;
    }
}
```

`minimumChecked()` is the number of tools of the bundle: an empty scan proves nothing.

## How it is built

| Class | Role |
|---|---|
| `src/EmagmaMcpCoreBundle.php` | Configuration, prepended defaults, arguments from the configuration, compiler passes |
| `src/DependencyInjection/McpCoreConfig.php` | What another bundle prepends |
| `src/DependencyInjection/Compiler/*` | The passes that reach the server's builder |
| `src/Authorization/*` | Grants, permissions, gates, the registry decorator |
| `src/Call/*` | The `tools/call` chain, `LocalToolCaller`, `ToolClassMap` |
| `src/Caller/*` | Who calls: OAuth client, client name, actor |
| `src/WriteConfirmation/*` | The trait, the policy, the preview |
| `src/Output/*`, `src/Privacy/*` | Field marks, pseudonymisation |
| `src/Redaction/*` | The redactor and its key providers |
| `src/Journal/*` | The contracts of the write journal |
| `src/Exception/*` | `RevertRefusedException` |
| `src/Instruction/*` | The server's instructions |
| `src/EventListener/*` | Around the SDK's request and response |
| `src/Command/ListPermissionsCommand.php` | `mcp:permissions` |
| `src/Testing/*` | Test cases and helpers for other bundles |
| `config/services.php` | The explicit service definitions |

### Services

Services are explicit in `config/services.php`, with ids prefixed by the alias:

- `emagma_mcp_core.permission_resolver`, `.grants_provider.deny_all`, `.grants_provider.allow_all`, `.authorization_registry`;
- `emagma_mcp_core.tool_call_handler`, `.tool_call_middleware.argument_error`, `.local_tool_caller`, `.tool_class_map`;
- `emagma_mcp_core.caller_store`, `.actor_provider.security`, `.actor_provider.null`, `.write_journal.null`, `.redactor`, `.instructions`;
- `emagma_mcp_core.listener.*`: the grants warning, the empty-object workaround, the untrusted-content notice, the write confirmation policy, the privacy context;
- `emagma_mcp_core.command.list_permissions`.

Aliases make them injectable:

- the five interfaces of "Replace by an alias" point to their default;
- the classes of "Inject" and the core's own implementations point to their service.

`emagma_mcp_core.registry` and `emagma_mcp_core.server` are aliases of the registry and of the server the core attaches to.

The arguments read from the configuration are set by the bundle class: no container parameter is added but `emagma_mcp_core.server`.

### Authorization

**A grant** is `mcp:<domain>:<resource>:<action>`.
`Grants::fromStrings()` ignores anything that does not match: wrong prefix, not three parts, unknown action.

`Grants::allows()` tries the permission's domain and resource, each against the wildcard `all`.
`mcp:all:<resource>:<action>` is never matched: a resource name means different things in different domains.
`read` is satisfied by `read`, `write` or `delete`; `write`, `delete` and `reveal` only by themselves.
`allowsAll([])` is `false`: a tool without `#[Permission]` is never authorised under real grants.

**The domain** is not written on the tool.
`ToolPermissionResolver::domainOf()` looks the handler's class up in `emagma_mcp_core.permission_domains` (namespace prefix to domain, the longest prefix wins).
It completes each `Permission` with `Permission::in()`.
A permission without a domain matches no grant.

**The registry** (`ToolAuthorizationRegistry`) decorates `mcp.server.<name>.registry`:

- `getTool()`, the path of `tools/call`: the grants first, and a refusal throws `ToolNotFoundException`, so that a forbidden tool is indistinguishable from an unknown one. Then the gates: the first `ToolRefusal` wins, and the tool is replaced by a reference with a permissive schema whose handler returns the refusal's `answer`. Then a notice is logged for a `#[Deprecated]` tool, which is still served.
- `getTools()`, the path of `tools/list`: drops what the grants refuse, what a gate refuses and what is deprecated; the rest goes through `ToolAnnotationsDeriver::derive()`.
- `hasTool()` answers as `getTool()` does on the grants.
- Resources: under real grants, only the `ui://` URIs found in `_meta.ui.resourceUri` of the tools the caller is served; everything else throws `ResourceNotFoundException`.
- Resource templates and prompts: served only under an explicit allow-all.
- `register*` and `unregister*` pass through.

**Annotations**: a tool that sets its own is left alone.
Otherwise `readOnlyHint` is true when no permission is `write` or `delete`, `destructiveHint` is true on a `delete`, `idempotentHint` is true on a read-only tool, and `openWorldHint` is false.

**Providers**: `DenyAllGrantsProvider` is the default alias, `AllowAllGrantsProvider` an explicit opt-in.
`AllowAllGrantsWarningListener` logs once per process at critical level when allow-all grants are served on an HTTP request.

### The `tools/call` chain

`ToolCallHandler` is tagged `mcp.request_handler` and takes `tools/call` over from the SDK.
It wraps a stock `CallToolHandler`, built on the decorated registry, in the services tagged `emagma_mcp_core.tool_call_middleware`, outermost first.
`ToolCallLocatorPass` copies the SDK's tool locator into it, so that tools are fetched from the container.

`ToolArgumentErrorMiddleware` is the innermost middleware (priority -1000).
It turns the SDK's schema-validation error (-32602 with `validation_errors`) into an `isError` result the model can correct.
An unknown or forbidden tool stays a protocol error.

### Around a request

| Listener | When | What |
|---|---|---|
| `EventListener\EmptyObjectArgumentListener` | SDK `RequestEvent` | Drops an optional object argument received empty |
| `Privacy\PrivacyContextConfigurator` | SDK `RequestEvent`, priority 1024 | Sets the privacy mode of the caller's client |
| `WriteConfirmation\WriteConfirmationPolicyConfigurator` | `kernel.request`, `console.command` | Re-applies the policy |
| `EventListener\UntrustedContentNoticeListener` | SDK `ResponseEvent`, priority 10 | Adds the notice to a marked result |

### Write confirmation

`WriteConfirmationTrait::confirmWrite()` returns `null` to proceed, or a refusal array:

1. The client supports elicitation: it is asked. Accepted with `confirm: true` proceeds; anything else answers `declined` or `cancelled`, unless the cancel came back with no content faster than `auto_cancel_threshold`, which falls through to step 2.
2. A session without a human channel: `WriteConfirmationPolicy::acceptsModelConfirmation()` decides from the tool's permissions and the client's name (`ClientInfo::of($session)->name`). Refused: `human_confirmation_required`. Accepted: `$confirm` proceeds, otherwise `elicitation_unavailable_preview_only` with the hint.
3. No context (console, tests): `$confirm` decides.

`WriteConfirmationPolicy` is a static holder, because a trait cannot be injected.
Unconfigured, it is `secureDefault()`: `write` and `delete` require a human.

### Caller

- **`McpCallerStore`**: the OAuth client id (`azp`) of the request, written by the host's access-token handler, reset with the kernel.
- **`ClientInfo::of()`**: the declared `clientInfo`, from the handshake's session or, without one, the request's `_meta`. What the handshake stored wins.
- **`ActorProviderInterface`**: who the call runs for. `SecurityActorProvider` gives the user identifier, trimmed and cut to 255 characters.

### Outputs and privacy

`OutputFieldsTrait::jsonSerialize()`:

- under `PrivacyContext::hides()`, unsets the `#[Surplus]` fields and replaces each non-empty string `#[Identifier]` by `PrivacyContext::token()`;
- adds `untrustedContent: {fields: [...]}`, naming the non-null `#[Untrusted]` fields that were not replaced by a token.

`Pseudonymiser::tokenFor()` gives `<kind>:<8 hex of HMAC-SHA256>`.
The value is lowercased and stripped of whitespace first.
The kind (`email`, `phone`, `login`, `id`) comes from the property name.

`PrivacyContext` is a static holder, because `jsonSerialize()` receives no context.
Unconfigured, it is `off`.

### Redaction

`SensitiveDataRedactor::redact()` walks a payload and replaces by the marker:

- the personal keys (providers and configuration, exact match, any case);
- any key containing a secret fragment;
- the opaque subtrees a provider names under a parent key;
- the email addresses inside remaining strings.

`redactDiff()` handles dotted keys: one sensitive segment redacts the whole pair.
`containsRedaction()` tells a scrubbed snapshot.
`redactText()` replaces the emails of a free text; `containsEmail()` tells one.

### Server instructions

1. A bundle declares a `McpInstructionInterface` service tagged `emagma_mcp_core.instruction`, by hand or with `#[AsMcpInstruction]`.
2. `McpInstructions` receives them highest priority first and joins the non-empty texts with a blank line.
3. `InstructionsPass` makes `McpInstructions::configure()` the configurator of the server's builder, which calls `setInstructions()` when the server is built. A literal `mcp.servers.<name>.instructions` is kept, as an instruction of priority 0 (`emagma_mcp_core.instruction.configured`).

### Compiler passes

- **`HandshakeEraPass`**: calls `withoutModernEra()` on the server's builder.
- **`InstructionsPass`** (priority 10): see above.
- **`ToolCallLocatorPass`** (priority -10, after `McpPass`): hands the tool locator to the `tools/call` handler and to `LocalToolCaller`.
- **`ToolClassMapPass`** (priority -10, after `McpPass`): fills `ToolClassMap` from the `addTool()` calls of the server's builder, so that a command or a worker reads it without building the server.

## Configuration reference

| Key | Default | Applied to |
|---|---|---|
| `server` | `default` | Parameter `emagma_mcp_core.server`, the two aliases, the registry decorated |
| `permission_domains` | `[]` | `ToolPermissionResolver` |
| `untrusted_content_notice` | `null` | `UntrustedContentNoticeListener`, when not null |
| `write_confirmation.require_human` | `[write, delete]` | The policy; an unknown action is rejected |
| `write_confirmation.trusted_clients` | `[]` | The policy; an empty name is rejected |
| `write_confirmation.auto_cancel_threshold` | `0.0` | The policy; at least 0 |
| `redaction.marker` | `__REDACTED__` | `SensitiveDataRedactor` |
| `redaction.personal_keys` | `[]` | `SensitiveDataRedactor` |
| `redaction.secret_fragments` | `[]` | `SensitiveDataRedactor` |
| `privacy.mode` | `pseudonymise` | `PrivacyContextConfigurator` |
| `privacy.secret` | `%env(default:kernel.secret:MCP_PRIVACY_SECRET)%` | `PrivacyContextConfigurator` |
| `privacy.clients.<id>` | none | `{mode, reveal: true}`; a plain string is read as the mode |

What the bundle prepends:

- **`monolog.channels`**: `emagma_mcp_core` and `emagma_mcp_core_authorization`, when MonologBundle is registered.
- **`mcp.servers.<server>.pagination_limit: 250`**: `tools/list` is one page, because the grants filter each page after the SDK has cut it.

## Before you expose the server

The defaults of this bundle are the cautious ones.
Each key below is a decision a deployment reviews before the server is reachable by a remote model.
The firewall, the proxies and the host checks are in the [auth bundle's developer guide](https://github.com/emagma/mcp-auth-bundle/blob/main/docs/developer-guide.md#before-you-expose-the-server).

### Who may confirm a write

- **What it does**: `write_confirmation.require_human` lists the actions for which the model's `confirm: true` is never accepted over an MCP session. A client with elicitation asks the person and the model's `confirm` is ignored; a client without it gets `human_confirmation_required`, unless it is a trusted client.
- **Default**: `[write, delete]`.
- **Risk**: `confirm` is a tool argument, so the model writes it, and the model reads text written by outsiders: product reviews, customer names, order notes. An instruction hidden in a review can make it confirm a refund. Emptying the list reopens that path on every client without elicitation.
- **What to set**: keep the default. Empty the list only on a deployment that has no person in the loop by design.
- **Without an MCP session** (a console command, a tool called as a service, a test): `confirm` decides, because the caller is the host itself. A real `tools/call` always carries a session, so this path cannot be reached from the network.

### Clients trusted to ask the person

- **What it does**: `write_confirmation.trusted_clients` names the clients whose `confirm: true` is accepted for a `require_human` action when they offer no elicitation. The name is the `clientInfo.name` the client sends at `initialize`: it is set by the client software, not by the model, so an injected instruction cannot forge it.
- **Default**: `[]`.
- **Risk**: for a listed client, the person's approval rests entirely on the client's own interface. The server has no proof that anyone approved.
- **What to set**: only clients whose approval behaviour you have checked yourself.

What three clients were observed to do (check it against the current version of each):

- **`Anthropic/ClaudeAI`** (claude.ai): the interface asks the user to allow each tool call.
- **`openai-mcp`** (ChatGPT): the approval is the one ChatGPT shows before a write action, sorting the tools by `readOnlyHint`. ChatGPT may not ask, depending on its permissions and its own assessment of the risk, and one approval may hold for the whole conversation.
- **`claude-code`** (Claude Code in a claude.ai cloud session): it declares elicitation and cancels it at once (next section). The approval happens in the conversation only.

The Sylius Assistant stack lists these three.

### Clients that cancel elicitation on their own

- **What it does**: `write_confirmation.auto_cancel_threshold` is a number of seconds. An elicitation answered `cancel`, with no content, faster than this is taken as the client's own cancel: the session is then handled as one without elicitation, and `trusted_clients` and `require_human` decide.
- **Default**: `0.0`, which disables the detection: every cancel is a refusal.
- **Risk**: a person who really cancels faster than the threshold is treated as absent. On a trusted client, the model's `confirm: true` then applies the write.
- **What to set**: leave it at `0.0` unless a client you trust cancels on its own; then keep it short (the Sylius Assistant stack uses `2.0`).

### Personal keys the redactor does not know

- **What it does**: before an audit entry or a trace is stored, `SensitiveDataRedactor` replaces by the marker the keys the bundles declare, any key containing a secret fragment, and the email addresses found in remaining strings. `redaction.personal_keys` and `redaction.secret_fragments` add to these lists.
- **Default**: `[]` for both.
- **Risk**: a backend with custom fields (a customer attribute of your own) has keys no bundle knows. Their values are stored in clear in the audit and in the traces.
- **What to set**: name them.

```yaml
emagma_mcp_core:
    redaction:
        personal_keys: ['loyaltyCardNumber']
        secret_fragments: ['hmac']
```

The redaction applies to what is persisted only.
The tool's answer and the confirmation preview keep the real values: the person who approves a change must see what changes.

### What the model sees of a person

- **What it does**: `privacy.mode` and `privacy.clients.<client id>` decide, per OAuth client of the caller (the `azp` claim the token handler stores in `McpCallerStore`), whether identifiers are pseudonymised.
- **Under `pseudonymise`**: an `#[Identifier]` becomes a stable token (`email:7f3a9c21`, an HMAC keyed by `privacy.secret`), a `#[Surplus]` field is left out, and names, addresses and free text pass, marked untrusted, because the model needs them to act. The token is the same in a customer, an address and an order: the model relates them without reading the value, and the tools take ids, never values.
- **Default**: `pseudonymise` for every client not listed. As long as `privacy.mode` keeps that value, a client gets `off` only when it is named, as a grant is; `mode: off` serves every unlisted client in clear.
- **Risk**: the answers sent to the model are where the data leaves your perimeter. `off` sends emails, phone numbers and birthdays to the model's provider.
- **What to set**: `off` only for a model you host yourself; `reveal: false` on a client whose provider must never receive a real value.

```yaml
emagma_mcp_core:
    privacy:
        mode: pseudonymise          # every client not listed
        clients:
            mcp-internal: off       # a model the merchant hosts
            mcp-llm: { mode: pseudonymise, reveal: false }
```

- **The secret**: `privacy.secret` defaults to the kernel secret; set `MCP_PRIVACY_SECRET` to rotate the tokens on their own. An empty secret stops the first MCP request.
- **Outside an MCP request** (console, tests): no mode is configured and nothing is hidden. No model reads these results.

### Revealing a person in clear

- **What it does**: a `get_*` tool marked `#[RevealsPersonalData]` takes `reveal: true`. Under a client that pseudonymises, that answer is served in clear. In the Sylius bundle these are `get_customers`, `get_addresses`, `get_orders` and `get_administrators`; never a `search_*` tool, because reading hundreds of emails in one call is the shape of an exfiltration.
- **Default**: refused without the grant `mcp:core:pii:reveal` (`reason: forbidden`). No `read`, `write` or `delete` grant gives it, not even `mcp:all:all:read`. `reveal: false` on the client refuses it even to a holder of the grant (`reveal_disabled_for_client`).
- **Risk**: the grant cuts across resources: one person comes out through four tools. Give it to the roles that need values, not to every reader.
- **Trace**: each person revealed goes to the write journal, so with the audit bundle installed it is an audit entry. The entries of one call share a batch id, returned in `revealed.batchId`.

The elicitation preview is not pseudonymised: the person confirms on the real values.

## Data

None.
The bundle owns no entity, table or file.

## Tests and quality

The bundle's tests run on their own.
`phpunit.xml.dist` has two suites:

- **`mcp-core-unit`** (`tests/Unit`): each class, the configuration, the service definitions and the compiler passes.
- **`mcp-core-integration`** (`tests/Integration`): a minimal kernel, `tests/Integration/Fixture/TestKernel.php`, with FrameworkBundle, `symfony/mcp-bundle`, the core and one `EchoTool`, closed by default or open with the allow-all grants.

`tests/Analysis` holds no tests: two classes that put the shipped traits under PHPStan, which analyses a trait only where it is used.

Alone, from the package directory:

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

In the monorepo of the Sylius Assistant stack:

```bash
# the package alone, in a throwaway copy with its own vendor/
bin/ci/package.sh mcp-core-bundle lowest
bin/ci/package.sh mcp-core-bundle highest

# the server's tools on every bundle, from the host
castor mcp:quality
```

- `bin/ci/packages.sh` runs the package script for each package.
- `bin/ci/mcp-server.sh` and `castor mcp:quality` still check the bundle with the server's tools.

## Traps

- **A tool without `#[Permission]`, or under a namespace without a domain, is silently invisible.** `mcp:permissions` exits in failure for it, and the conformance test cases make it a test failure.
- **A gate must not depend on the caller.** Its refusal reason is shown only to a caller the grants already allow, but it is evaluated for all.
- **The gates are built in the registry's constructor**: a gate that cannot be built stops every MCP request, on purpose.
- **`mcp:permissions` uses the undecorated registry** and injects the server only to make the builder fill it. Do not remove the unused argument.
- **Static holders** (`WriteConfirmationPolicy`, `PrivacyContext`): the first is re-applied on every request and command, the second reset with the kernel. In a unit test, call `WriteConfirmationPolicy::reset()` and `PrivacyContext::reset()` when you configure them.
- **The privacy mode is set on the SDK's `RequestEvent`, not on `kernel.request`**: the client id is known only once the firewall has validated the token.
- **Elicitation suspends a Fiber inside `$next`**: never flush an OpenTelemetry provider inside it.
- **`max_execution_time`** bounds how long an elicitation may wait (60 seconds in the Sylius Assistant stack).
- **`targetType` and `targetCode` are never scrubbed**: a write tool must not put personal data in `targetCode`.
- **`token` is not a secret fragment** on purpose: it would swallow identifiers such as `tokenValue`. Add a precise fragment (`token_hmac`) through `redaction.secret_fragments`.
- **The redaction marker**: changing it leaves older scrubbed entries unrecognised by `containsRedaction()`. The Sylius Assistant stack keeps `__REDACTED_BY_SYLIUS_ASSISTANT__`.
- **`mcp:all:<resource>:<action>` grants nothing.** Use `mcp:<domain>:all:<action>` or `mcp:all:all:<action>`.
- **`EmptyObjectArgumentListener` is a workaround** for `mcp/sdk` decoding `{}` and `[]` alike: check it is still needed when the SDK is upgraded.
- **`HandshakeEraPass`** keeps the server off the 2026-07-28 revision, which reruns an eliciting tool from the start while writes elicit midway.
- **A pass reads its services by id**: `hasDefinition()` is false for an alias, so a pass that names a class instead of the id would skip its work silently.
