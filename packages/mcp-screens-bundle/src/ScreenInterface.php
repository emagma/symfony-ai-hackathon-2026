<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle;

use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpScreensBundle\Exception\ScreenException;
use Mcp\Server\RequestContext;

/**
 * A small screen shown in the conversation: a focused operation (the prices of a product, one address),
 * never a back office. A service tagged `emagma_mcp_screens.screen` (autoconfigured, `EmagmaMcpScreensBundle::SCREEN_TAG`);
 * the app and its three tools are the same for all.
 */
interface ScreenInterface
{
    /**
     * What `open_screen` is given: `[a-z0-9_]+`.
     */
    public function name(): string;

    /**
     * For the model: what the screen shows and which `params` it takes.
     */
    public function description(): string;

    /**
     * What the caller must be allowed to do to see the screen, qualified with their domain.
     *
     * @return list<Permission>
     */
    public function readPermissions(): array;

    /**
     * What the caller must be allowed to do to save it; without them the screen is shown read-only.
     *
     * @return list<Permission>
     */
    public function writePermissions(): array;

    /**
     * The Twig fragment of the screen. It receives `data` (what load() returned), `params`, `mode`
     * (display or form), `can_edit`, `errors` and `message`.
     */
    public function template(): string;

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the `data` of the template
     *
     * @throws ScreenException when the screen cannot be shown (unknown product, missing param)
     */
    public function load(array $params): array;

    /**
     * Validates the values and writes them through the server's own write path (a tool's service), which
     * asks the human and journals as for any other write.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $values the fields of the form
     */
    public function submit(array $params, array $values, ?RequestContext $context): ScreenResult;
}
