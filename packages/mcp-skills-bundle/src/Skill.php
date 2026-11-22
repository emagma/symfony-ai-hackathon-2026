<?php

declare(strict_types=1);

namespace Emagma\McpSkillsBundle;

use Emagma\McpCoreBundle\Authorization\Permission;

/**
 * A skill as the business wrote it: a folder holding a SKILL.md (Agent Skills) and, at times, annexes.
 */
final readonly class Skill
{
    /**
     * @param list<Permission>      $requires what the caller must be allowed to do for the skill to work: the grants of the tools it uses
     * @param array<string, string> $files    relative path => content, SKILL.md first
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $version,
        public array $requires,
        public array $files,
    ) {
    }

    public function uri(string $pack, string $file = SkillRepository::MANIFEST): string
    {
        return \sprintf('skill://%s/%s/%s', $pack, $this->name, $file);
    }
}
