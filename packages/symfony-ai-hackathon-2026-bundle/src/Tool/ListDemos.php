<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\SymfonyAiHackathon2026Bundle\Demo;
use Emagma\SymfonyAiHackathon2026Bundle\DemoRepository;
use Mcp\Capability\Attribute\McpTool;

/**
 * The MCP tool `list_demos`: the menu of the tour.
 *
 * @internal
 */
#[McpTool(
    name: 'list_demos',
    description: 'The guided tour of this server for the Symfony AI & MCP Hackathon 2026: the demos to run, in'
        . ' order, each with the Symfony AI notions it exercises. Returns {items: [{name, position, title, user,'
        . ' symfonyAi}], total, hint}. Call it when the user asks what they can try, asks for a tour, a demo or'
        . ' "what does this do", or does not know where to start. Present the list as a short menu numbered with'
        . ' each `position` (the tour starts at 0, as HACKATHON.md does: "demo 3" is position 3),'
        . ' propose to start with the first one they have not done, and call get_demo for the one they pick.'
        . ' You are the guide: one demo at a time, never the whole tour in one answer.',
)]
#[Permission(resource: 'demo', action: Action::Read)]
final readonly class ListDemos
{
    public function __construct(
        private DemoRepository $demos,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $items = array_map(static fn (Demo $demo): array => [
            'name' => $demo->name,
            'position' => $demo->position,
            'title' => $demo->title,
            'user' => $demo->user,
            'symfonyAi' => $demo->symfonyAi,
        ], $this->demos->all());

        return [
            'items' => $items,
            'total' => \count($items),
            'hint' => 'Show the titles as a menu numbered with their position, starting at 0, and ask which one to run; get_demo gives its steps.',
        ];
    }
}
