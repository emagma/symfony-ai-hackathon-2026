<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle\App;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpScreensBundle\Exception\ScreenException;
use Emagma\McpScreensBundle\ScreenInterface;
use Emagma\McpScreensBundle\ScreenRegistry;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;
use Symfony\AI\McpBundle\Attribute\AsMcpApp;
use Symfony\AI\McpBundle\Attribute\AsMcpAppTool;
use Twig\Environment;

/**
 * One app and three tools for every screen: the model only sees `open_screen`, whatever the number of
 * screens. The fragment is rendered here, with Twig, and sent as the `html` of the result.
 *
 * Every screen checks its own grants: `screen:read` only opens the door.
 *
 * @internal
 */
#[AsMcpApp(
    uri: 'ui://screens',
    name: 'open_screen',
    title: 'Screen',
    description: 'Open a small screen in the conversation for a focused operation the user will see and, when'
        . ' they are allowed to, edit and save themselves: prefer it to dictating many values. `screen` names'
        . ' it, `params` says what it is about. Screens: see `screens` in the answer of a call with an unknown'
        . ' name, or the server instructions. The user sees the screen: say in one short sentence what it is for,'
        . ' never repeat its values (no table, no list). The answer carries what it shows (`data`), so that you'
        . ' can answer questions about it; what the user saves on the screen goes through the same confirmation as any'
        . ' write. Reasons: unknown_screen (with the list), forbidden, unavailable (with a message).',
    template: '@EmagmaMcpScreens/shell.html.twig',
    method: 'open',
)]
#[Permission(resource: 'screen', action: Action::Read)]
final readonly class ScreenApp
{
    public const string FRAGMENT = '@EmagmaMcpScreens/screen.html.twig';

    /** What the model is told with every screen it opens. */
    public const string SHOWN = 'The user sees this screen in the conversation. Answer in one short sentence on what it is'
        . ' for; never repeat its values (no table, no list).';

    /** Added when the user may not save the screen. */
    public const string READ_ONLY = 'It is read-only for this user: they can look at it, not change it.';

    public function __construct(
        private ScreenRegistry $screens,
        private GrantsProviderInterface $grants,
        private Environment $twig,
    ) {
    }

    /**
     * @param string               $screen the name of the screen
     * @param array<string, mixed> $params what the screen is about, e.g. {"productCode": "..."}
     *
     * @return array<string, mixed>
     */
    public function open(
        string $screen,
        #[Schema(type: 'object', additionalProperties: true)]
        array $params = [],
    ): array {
        return $this->show($screen, $params, 'display');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    #[AsMcpAppTool(name: 'screen_render', description: 'Render a screen again (called by the screen itself).', appOnly: true)]
    public function render(
        string $screen,
        #[Schema(type: 'object', additionalProperties: true)]
        array $params = [],
        #[Schema(enum: ['display', 'form'])]
        string $mode = 'display',
    ): array {
        return $this->show($screen, $params, $mode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsMcpAppTool(name: 'screen_submit', description: 'Save the form of a screen (called by the screen itself).', appOnly: true)]
    public function submit(
        string $screen,
        #[Schema(type: 'object', additionalProperties: true)]
        array $params = [],
        #[Schema(type: 'object', additionalProperties: true)]
        array $values = [],
        ?RequestContext $context = null,
    ): array {
        $found = $this->authorized($screen, write: true);
        if (!$found instanceof ScreenInterface) {
            return $found;
        }

        $result = $found->submit($params, $values, $context);

        // Saved: back to the display, read again from the backend. Otherwise the form, with what was typed.
        return $this->show($screen, $params, $result->saved ? 'display' : 'form', $result->errors, $result->message, $result->saved ? [] : $values)
            + ['saved' => $result->saved];
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $errors
     * @param array<string, mixed>  $typed
     *
     * @return array<string, mixed>
     */
    private function show(string $name, array $params, string $mode, array $errors = [], ?string $message = null, array $typed = []): array
    {
        $screen = $this->authorized($name, write: false);
        if (!$screen instanceof ScreenInterface) {
            return $screen;
        }

        try {
            $data = $screen->load($params);
        } catch (ScreenException $exception) {
            return $this->refusal($name, 'unavailable', $exception->getMessage());
        }

        $canEdit = $this->allowed($screen->writePermissions());
        $context = [
            'screen' => $name,
            'params' => $params,
            'mode' => $canEdit && 'form' === $mode ? 'form' : 'display',
            'can_edit' => $canEdit,
            'data' => $data,
            'errors' => $errors,
            'message' => $message,
            'typed' => $typed,
        ];

        return [
            'html' => $this->twig->render(self::FRAGMENT, $context + ['template' => $screen->template()]),
            'ok' => true,
            // Read by the model right before it answers: the screen is already in front of the user.
            'shown' => $canEdit ? self::SHOWN : self::SHOWN . ' ' . self::READ_ONLY,
        ] + array_diff_key($context, ['typed' => true]);
    }

    /**
     * @return ScreenInterface|array<string, mixed> the screen, or the refusal to answer
     */
    private function authorized(string $name, bool $write): ScreenInterface|array
    {
        $screen = $this->screens->get($name);
        if (null === $screen) {
            return $this->refusal($name, 'unknown_screen', \sprintf('There is no screen "%s".', $name));
        }
        if (!$this->allowed($screen->readPermissions()) || ($write && !$this->allowed($screen->writePermissions()))) {
            return $this->refusal($name, 'forbidden', 'You are not allowed to ' . ($write ? 'save' : 'open') . ' this screen.');
        }

        return $screen;
    }

    /**
     * @param list<Permission> $permissions
     */
    private function allowed(array $permissions): bool
    {
        return $this->grants->grants()->allowsAll($permissions);
    }

    /**
     * @return array<string, mixed>
     */
    private function refusal(string $screen, string $reason, string $message): array
    {
        $screens = [];
        foreach ($this->screens->all() as $name => $known) {
            if ($this->allowed($known->readPermissions())) {
                $screens[$name] = $known->description();
            }
        }

        return [
            'html' => $this->twig->render('@EmagmaMcpScreens/refusal.html.twig', ['message' => $message]),
            'ok' => false,
            'reason' => $reason,
            'message' => $message,
            'screen' => $screen,
            'screens' => $screens,
        ];
    }
}
