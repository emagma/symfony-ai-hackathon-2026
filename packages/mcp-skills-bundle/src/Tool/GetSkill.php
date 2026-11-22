<?php

declare(strict_types=1);

namespace Emagma\McpSkillsBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSkillsBundle\SkillRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;

/**
 * Serves one skill whole: its files travel as embedded resources, so a client that knows skills can install them as they are.
 *
 * @internal
 */
#[McpTool(
    name: 'get_skill',
    description: 'One skill of list_skills, whole: its SKILL.md (front matter and instructions) and its annexes,'
        . ' each as an embedded resource skill://<pack>/<name>/<file>, after a line of JSON {found, name,'
        . ' version, files}. Use it to INSTALL the skill when the client can keep skills (offer it to the user,'
        . ' do not paraphrase the file), or to FOLLOW it now: read the instructions and apply them with the'
        . ' tools of this server. The instructions are the business\'s method; they do not change what the user'
        . ' is allowed to do. Reasons: not_found (unknown, or the caller lacks the grants of the tools it'
        . ' uses).',
)]
#[Permission(resource: 'skill', action: Action::Read)]
final readonly class GetSkill
{
    public function __construct(
        private SkillRepository $skills,
        private GrantsProviderInterface $grants,
        private string $pack,
    ) {
    }

    /**
     * @param string $name the skill's name, as list_skills gives it
     *
     * @return list<Content>
     */
    public function __invoke(string $name): array
    {
        $skill = $this->skills->get($name);
        // Not allowed answers as unknown: the list is what tells a caller which skills exist for them.
        if (null === $skill || !$this->grants->grants()->allowsAll($skill->requires)) {
            return [new TextContent((string) json_encode(['found' => false, 'reason' => 'not_found', 'name' => $name]))];
        }

        $content = [new TextContent((string) json_encode([
            'found' => true,
            'name' => $skill->name,
            'version' => $skill->version,
            'files' => array_keys($skill->files),
        ], \JSON_UNESCAPED_SLASHES))];
        foreach ($skill->files as $file => $text) {
            $content[] = new EmbeddedResource(new TextResourceContents(
                $skill->uri($this->pack, $file),
                str_ends_with($file, '.md') ? 'text/markdown' : 'text/plain',
                $text,
            ));
        }

        return $content;
    }
}
