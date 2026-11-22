# MCP Screens Bundle: developer guide

- **Composer name**: `emagma/mcp-screens-bundle`
- **Namespace**: `Emagma\McpScreensBundle\`
- **Bundle class**: `Emagma\McpScreensBundle\EmagmaMcpScreensBundle`, an `AbstractBundle`; there is no Extension nor Configuration class.
- **Config alias**: `emagma_mcp_screens` (it takes no key)
- **Twig namespace**: `@EmagmaMcpScreens` (the `templates/` directory of the package; `getPath()` returns the package root)
- **Depends on**: `emagma/mcp-core-bundle` `^0.1`, `mcp/sdk`, `symfony/mcp-bundle`, `symfony/twig-bundle`.
- **Depended on by**: the bundles that bring screens.
- **In the Sylius Assistant stack**: the layer is `Screens: [Core]` in `apps/mcp-server/deptrac.yaml`; `mcp-sylius-screens-bundle` brings the screens.

## Public API

- `ScreenInterface`: what a screen is.
- The tag `emagma_mcp_screens.screen` (`EmagmaMcpScreensBundle::SCREEN_TAG`), set by autoconfiguration on every `ScreenInterface` service.
- `ScreenResult` and `ScreenException`.
- `ScreenRegistry`: `get(name)` and `all()`.
- The Twig namespace `@EmagmaMcpScreens` and the HTML conventions of the shell (see "The fragment").

Everything else is `@internal`.

## How it is built

One MCP App and three MCP Tools serve every screen.
The tool list of the model holds one entry, `open_screen`, whatever the number of screens.

| File | Role |
|---|---|
| `src/EmagmaMcpScreensBundle.php` | Prepended defaults, autoconfiguration of the tag |
| `src/ScreenInterface.php` | What a screen is |
| `src/ScreenRegistry.php` | The tagged screens, by name, sorted |
| `src/ScreenResult.php` | The outcome of a submit |
| `src/Exception/ScreenException.php` | "This screen cannot be shown", with a message |
| `src/App/ScreenApp.php` | The app `ui://screens` and its three tools |
| `src/Instruction/ScreensInstruction.php` | The screens in the server's instructions |
| `templates/shell.html.twig` | The static document of the app: style and script |
| `templates/screen.html.twig` | The holder around the fragment of every screen |
| `templates/refusal.html.twig` | The message of a refusal |

### The three tools

`ScreenApp` carries `#[AsMcpApp(uri: 'ui://screens', name: 'open_screen', ...)]` and `#[Permission(resource: 'screen', action: Action::Read)]`.

| MCP Tool | Method | Arguments | Visible to |
|---|---|---|---|
| `open_screen` | `open()` | `screen`, `params` | the model |
| `screen_render` | `render()` | `screen`, `params`, `mode` | the app only |
| `screen_submit` | `submit()` | `screen`, `params`, `values` | the app only |

- `mode` is `display` (default) or `form`.
- `params` and `values` are free objects.
- `screen_render` and `screen_submit` are `appOnly`.
- `submit()` also receives the SDK's `RequestContext`, handed to the screen so that its write can ask the human.

### A display

1. `open()` or `render()` calls `show()`.
2. `authorized()` finds the screen (`unknown_screen` otherwise) and checks `readPermissions()` (`forbidden` otherwise).
3. `load($params)` returns the data.
   A `ScreenException` becomes `reason: unavailable` with its message.
4. `can_edit` is true when the caller holds `writePermissions()`.
   The mode is `form` only when the form was asked and `can_edit` is true.
5. `screen.html.twig` is rendered: a holder `<div class="screen" data-screen data-params>`, the message, then the template of the screen.
6. The result is `html`, `ok: true`, `shown`, `screen`, `params`, `mode`, `can_edit`, `data`, `errors`, `message`.
   `shown` tells the model that the user already sees the screen (`ScreenApp::SHOWN`), and that it is read-only when `can_edit` is false (`ScreenApp::READ_ONLY`).

### A save

1. `submit()` checks the read and the write permissions.
2. `ScreenInterface::submit($params, $values, $context)` returns a `ScreenResult`.
3. Saved: the display again, read from the backend, with the message.
   Not saved: the form again, with the errors, the message and what was typed.
4. The result gains `saved: true|false`.

### A refusal

A refusal answers `html`, `ok: false`, `reason`, `message`, `screen` and `screens`.
`screens` lists the name and description of every screen whose read permissions the caller holds.
This is how the model learns which screens exist, besides the server's instructions.

### The server's instructions

`emagma_mcp_screens.instruction` (`ScreensInstruction`, tag `emagma_mcp_core.instruction`, priority 0) lists every screen with its description.
The model names the right screen at once, instead of guessing a name and showing the user a refusal first.
No screen, no text.

### Authorization

Three levels:

- **The door**: the three tools resolve to `mcp:core:screen:read`.
  The domain `core` comes from `McpCoreConfig::prependPermissionDomain()`.
- **The screen**: `ScreenApp` checks `readPermissions()` and `writePermissions()` on every call of every tool.
- **The document**: the core serves a `ui://` resource only to a caller served a tool that links to it.
  `ui://screens` goes to whoever may call `open_screen`.

A screen writes through the backend's own write path, so the save is confirmed by the human and journaled like the same tool call.

## Add a screen

A screen is a service implementing `ScreenInterface`, plus a Twig fragment.
Autoconfiguration tags it; a service declared without autoconfiguration tags itself with `emagma_mcp_screens.screen`.

```php
final class NoteScreen implements ScreenInterface
{
    public function name(): string
    {
        return 'note'; // [a-z0-9_]+, what open_screen is given
    }

    public function description(): string
    {
        return 'One note, editable. params: {id}.'; // for the model
    }

    public function readPermissions(): array
    {
        return [new Permission('note', Action::Read, 'demo')]; // mcp:demo:note:read
    }

    public function writePermissions(): array
    {
        return [new Permission('note', Action::Write, 'demo')];
    }

    public function template(): string
    {
        return '@Acme/note.html.twig';
    }

    public function load(array $params): array
    {
        if (!isset($params['id'])) {
            throw new ScreenException('This screen needs params.id.');
        }

        return ['note' => $this->note];
    }

    public function submit(array $params, array $values, ?RequestContext $context): ScreenResult
    {
        $note = \is_string($values['note'] ?? null) ? trim($values['note']) : '';
        if ('' === $note) {
            return ScreenResult::invalid(['note' => 'Required.']);
        }
        // a real screen calls the backend's write path with $context

        return ScreenResult::saved('Note saved.');
    }
}
```

The fixture of the bundle's tests is `tests/Unit/Fixture/NoteScreen.php`.

### The fragment

```twig
<h1>Note</h1>
{% if mode == 'form' %}
    <form data-screen-form novalidate>
        <label class="field">
            <span>Note *</span>
            <span>
                <input name="note" value="{{ typed.note ?? data.note }}" required{{ errors.note is defined ? ' aria-invalid="true"' }}>
                {% if errors.note is defined %}<span class="error">{{ errors.note }}</span>{% endif %}
            </span>
        </label>
        <div class="actions">
            <button class="primary" type="submit">Save</button>
            <button type="button" data-screen-mode="display">Cancel</button>
        </div>
    </form>
{% else %}
    <p>{{ data.note }}</p>
    {% if can_edit %}
        <button class="primary" type="button" data-screen-mode="form">Edit</button>
    {% endif %}
{% endif %}
```

The variables of the fragment:

| Variable | Content |
|---|---|
| `data` | What `load()` returned |
| `params` | The params of the call |
| `mode` | `display` or `form` (`form` only if `can_edit`) |
| `can_edit` | The caller holds the write permissions |
| `errors` | Field name to message, after an invalid submit |
| `message` | The message of the last submit, or null |
| `typed` | The values of a submit that did not save |
| `screen` | The name of the screen |

The holder already shows `message`: the fragment does not repeat it.

The HTML conventions the script of the shell understands:

- **`<form data-screen-form>`**: on submit, after `reportValidity()`, the buttons are disabled and `screen_submit` is called.
  The named fields are the `values`; every value is a string.
- **`data-screen-mode="form"` or `"display"`** on any clickable element: calls `screen_render` with that mode.
- **`data-screen-param="<name>"`** on a field (a `<select>`): on change, sets `params.<name>` and shows the display again.
- **`screen` and `params`** are never written by the fragment: the script reads them from the closest `[data-screen]`.
- **CSS classes** of the shell: `sub`, `num`, `field`, `error`, `message ok|ko`, `actions`, `primary`, `muted`.

The shell extends `@Mcp/app/base.html.twig` of `symfony/mcp-bundle`.
It reports the height of its content to the host, so that the frame follows the screen.

### ScreenResult

| Factory | When |
|---|---|
| `ScreenResult::saved(string $message)` | Written |
| `ScreenResult::invalid(array $errors, string $message)` | Wrong values, by field name |
| `ScreenResult::notSaved(string $message)` | Declined, refused by the backend, nothing changed |

## Configuration

`emagma_mcp_screens` takes no key.
The bundle prepends, for other bundles:

- `emagma_mcp_core.permission_domains`: `Emagma\McpScreensBundle\` to `core`.
- `mcp.servers.<server>.registry.apps`: `Emagma\McpScreensBundle\App\`, on the server the core attaches to.

## Services

- `emagma_mcp_screens.registry`, aliased to `ScreenRegistry`.
- `ScreenApp`, loaded by namespace with autowiring: it is the unit `symfony/mcp-bundle` discovers.

## Data

None: no entity, no table, no file written.

## Tests and quality

The package runs on its own: `composer install`, then `vendor/bin/phpunit`, `vendor/bin/ecs check`, `vendor/bin/phpstan analyse`.

- `tests/Unit/ScreenAppTest.php` covers the app, the registry and the result with an in-memory screen.
- `tests/Integration/ScreensBundleTest.php` boots a minimal kernel: the tag, and the templates under `@EmagmaMcpScreens`.

## Traps

- **`screen:read` is not an authorization to see data**: a screen with empty `readPermissions()` is open to every holder of `mcp:core:screen:read`.
  Declare the grants of everything `load()` reads.
- **Keep the permissions in step with the code**: a screen that starts reading another resource must add its permission.
- **`submit()` must check again**: `values` come from the browser.
  Reload the current state, validate, write only what changed.
- **Field names are the keys of `errors` and `typed`**: an error under another key is not shown.
- **All `values` are strings**, and an unchecked checkbox is absent.
- **Render with `strict_variables`**: write `errors.x is defined` and `typed.x ?? ...`.
- **A confirmation asked during `screen_submit`** is bounded by the server's execution time limit.
