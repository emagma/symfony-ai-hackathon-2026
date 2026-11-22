<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Skill;

use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\McpBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The skills the MCP server offers the logged-in user (`list_skills`), next to
 * the ones they installed here.
 */
final class SkillCatalog
{
    public const string HEADING = '## Installed skills';

    public function __construct(
        #[Autowire(service: 'mcp.client.sylius.server.shop')]
        private readonly ServerConnectionInterface $connection,
        private readonly SkillStore $store,
    ) {
    }

    /**
     * What the server offers this user, each with what is installed of it; then
     * the installed skills the server no longer offers. Needs the user's bearer
     * (TurnContext::start).
     *
     * @return list<array{name: string, description: string, version: string|null, installed: string|null}>
     */
    public function all(string $owner): array
    {
        $installed = $this->store->installed($owner);

        $skills = [];
        foreach ($this->offered() as $name => $skill) {
            $skills[] = ['name' => $name] + $skill + ['installed' => $installed[$name]['version'] ?? null];
            unset($installed[$name]);
        }
        foreach ($installed as $name => $skill) {
            $skills[] = ['name' => $name, 'description' => $skill['description'], 'version' => null, 'installed' => $skill['version']];
        }

        return $skills;
    }

    /**
     * Installs a skill the server offers this user; false when it does not.
     */
    public function install(string $owner, string $name): bool
    {
        $skill = $this->offered()[$name] ?? null;
        if (null === $skill) {
            return false;
        }
        $this->store->install($owner, $name, $skill['version'], $skill['description']);

        return true;
    }

    /**
     * The part of the system prompt that tells the model which skills the user
     * installed and how to use one: read its instructions on the server, then
     * follow them. Empty without any.
     */
    public function prompt(string $owner): string
    {
        $installed = $this->store->installed($owner);
        if ([] === $installed) {
            return '';
        }

        $lines = [];
        foreach ($installed as $name => $skill) {
            $lines[] = \sprintf('- `%s` (v%s) - %s', $name, $skill['version'], $skill['description']);
        }

        return self::HEADING . "\n\n"
            . 'The user installed these skills: proven methods, served by the MCP server. When their request matches one'
            . ' of them, first call `get_skill` with its name to read its instructions, then follow them with the tools.'
            . ' A skill missing from this list is not installed: offer to install it (the "Skills" panel) rather than'
            . " apply it.\n\n"
            . implode("\n", $lines);
    }

    /**
     * @return array<string, array{description: string, version: string}>
     */
    private function offered(): array
    {
        try {
            $result = $this->connection->callTool('list_skills');
        } catch (ExceptionInterface) {
            // No such tool for this user, or a server without skills: nothing is offered.
            return [];
        }

        $items = \is_array($result->structuredContent) ? ($result->structuredContent['items'] ?? null) : null;
        $offered = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null) && \is_string($item['description'] ?? null) && \is_string($item['version'] ?? null)) {
                $offered[$item['name']] = ['description' => $item['description'], 'version' => $item['version']];
            }
        }

        return $offered;
    }
}
