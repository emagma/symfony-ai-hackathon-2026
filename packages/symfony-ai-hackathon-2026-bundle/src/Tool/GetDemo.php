<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\SymfonyAiHackathon2026Bundle\Demo;
use Emagma\SymfonyAiHackathon2026Bundle\DemoRepository;
use Mcp\Capability\Attribute\McpTool;

/**
 * The MCP tool `get_demo`: one demo of the tour.
 *
 * @internal
 */
#[McpTool(
    name: 'get_demo',
    description: 'One demo of the hackathon tour (list_demos): {found, name, title, user, prompts, guide,'
        . ' symfonyAi, code, next}. `prompts` are the sentences the USER types in the chat, in order: quote'
        . ' the first one for them to send, do not run it yourself unless they ask you to: the point is that'
        . ' they see the tools work. `guide` says what to do outside the chat and what to look at; `user` is'
        . ' the demo account to be logged in as; `symfonyAi` and `code` are for who wants to read how it is'
        . ' built; `next` is the demo to propose afterwards. Keep it short: what to type now, what to watch.'
        . ' Reasons: not_found (with the names that exist).',
)]
#[Permission(resource: 'demo', action: Action::Read)]
final readonly class GetDemo
{
    public function __construct(
        private DemoRepository $demos,
    ) {
    }

    /**
     * @param string $name the demo's name, as list_demos gives it
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $name): array
    {
        $demo = $this->demos->get($name);
        if (null === $demo) {
            return [
                'found' => false,
                'reason' => 'not_found',
                'name' => $name,
                'names' => array_map(static fn (Demo $known): string => $known->name, $this->demos->all()),
            ];
        }

        $next = $this->demos->after($demo);

        return [
            'found' => true,
            'name' => $demo->name,
            'position' => $demo->position,
            'title' => $demo->title,
            'user' => $demo->user,
            'prompts' => $demo->prompts,
            'guide' => $demo->guide,
            'symfonyAi' => $demo->symfonyAi,
            'code' => $demo->code,
            'next' => null === $next ? null : ['name' => $next->name, 'title' => $next->title],
        ];
    }
}
