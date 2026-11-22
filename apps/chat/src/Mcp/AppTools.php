<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Mcp;

use Mcp\Schema\Tool;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What the server's tools say about MCP Apps (`_meta.ui`): which tool opens a
 * screen, and which ones only a screen may call.
 *
 * The chat is the host: it keeps the app-only tools away from the model, and
 * lets a screen call the tools of its own app, no other.
 */
final class AppTools implements ResetInterface
{
    /** @var array<string, array{uri: string, appOnly: bool}>|null by tool name */
    private ?array $apps = null;

    public function __construct(
        #[Autowire(service: 'mcp.client.sylius.server.shop')]
        private readonly ServerConnectionInterface $connection,
    ) {
    }

    /**
     * The `ui://` resource a tool is linked to, null for a tool without a screen.
     * A name as the model knows it (`shop_open_screen`) is understood too.
     */
    public function uriOf(string $tool): ?string
    {
        return $this->find($tool)['uri'] ?? null;
    }

    /**
     * A tool only a screen may call is not for the model.
     */
    public function isAppOnly(Tool $tool): bool
    {
        return self::describe($tool)['appOnly'] ?? false;
    }

    /**
     * A screen calls the tools of its own app only.
     */
    public function belongsTo(string $tool, string $uri): bool
    {
        return ($this->apps()[$tool]['uri'] ?? null) === $uri;
    }

    public function reset(): void
    {
        $this->apps = null;
    }

    /**
     * @return array{uri: string, appOnly: bool}|null
     */
    private function find(string $tool): ?array
    {
        foreach ($this->apps() as $name => $app) {
            if ($tool === $name || str_ends_with($tool, '_' . $name)) {
                return $app;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{uri: string, appOnly: bool}>
     */
    private function apps(): array
    {
        if (null !== $this->apps) {
            return $this->apps;
        }

        $apps = [];
        foreach ($this->connection->getTools() as $tool) {
            if (null !== $app = self::describe($tool)) {
                $apps[$tool->name] = $app;
            }
        }

        return $this->apps = $apps;
    }

    /**
     * @return array{uri: string, appOnly: bool}|null
     */
    private static function describe(Tool $tool): ?array
    {
        $ui = $tool->meta['ui'] ?? null;
        if (!\is_array($ui) || !\is_string($ui['resourceUri'] ?? null)) {
            return null;
        }
        $visibility = \is_array($ui['visibility'] ?? null) ? $ui['visibility'] : ['model', 'app'];

        return ['uri' => $ui['resourceUri'], 'appOnly' => !\in_array('model', $visibility, true)];
    }
}
