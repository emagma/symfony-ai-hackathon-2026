<?php

declare(strict_types=1);

namespace Emagma\McpSkillsBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSkillsBundle\Skill;
use Emagma\McpSkillsBundle\SkillRepository;
use Mcp\Capability\Attribute\McpTool;

/**
 * Lists the skills the caller holds the grants to run.
 *
 * @internal
 */
#[McpTool(
    name: 'list_skills',
    description: 'The skills this business offers its staff: proven methods (a morning brief, opening a sales'
        . ' channel, ...) written as Agent Skills that compose the tools of this server. Returns {items:'
        . ' [{name, description, version, uri}], total, pack}: only the skills the caller is allowed to run'
        . ' (they hold the grants of every tool the skill uses). Call it when the user asks what skills exist,'
        . ' asks to install them, or to check whether an installed skill has a newer version. Then get_skill'
        . ' for the content of one.',
)]
#[Permission(resource: 'skill', action: Action::Read)]
final readonly class ListSkills
{
    public function __construct(
        private SkillRepository $skills,
        private GrantsProviderInterface $grants,
        private string $pack,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $grants = $this->grants->grants();
        $items = [];
        foreach ($this->skills->all() as $skill) {
            if ($grants->allowsAll($skill->requires)) {
                $items[] = self::describe($skill, $this->pack);
            }
        }

        return ['items' => $items, 'total' => \count($items), 'pack' => $this->pack];
    }

    /**
     * @return array{name: string, description: string, version: string, uri: string}
     */
    public static function describe(Skill $skill, string $pack): array
    {
        return ['name' => $skill->name, 'description' => $skill->description, 'version' => $skill->version, 'uri' => $skill->uri($pack)];
    }
}
