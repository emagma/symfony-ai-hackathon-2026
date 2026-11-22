<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Skill;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The skills each user installed in this chat: one file per user under
 * var/skills/. What is kept is what tells a model when to use a skill - its
 * name, description and version; the instructions stay on the MCP server and
 * are read when the skill is used, so they are never out of date.
 */
final class SkillStore
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/skills')]
        private readonly string $directory,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return array<string, array{version: string, description: string}> by name, sorted
     */
    public function installed(string $owner): array
    {
        $path = $this->path($owner);
        $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        $skills = [];
        foreach (\is_array($stored) ? $stored : [] as $name => $skill) {
            if (\is_string($name) && \is_array($skill) && \is_string($skill['version'] ?? null) && \is_string($skill['description'] ?? null)) {
                $skills[$name] = ['version' => $skill['version'], 'description' => $skill['description']];
            }
        }
        ksort($skills);

        return $skills;
    }

    public function install(string $owner, string $name, string $version, string $description): void
    {
        $this->write($owner, [$name => ['version' => $version, 'description' => $description]] + $this->installed($owner));
    }

    public function remove(string $owner, string $name): void
    {
        $this->write($owner, array_diff_key($this->installed($owner), [$name => true]));
    }

    /**
     * @param array<string, array{version: string, description: string}> $skills
     */
    private function write(string $owner, array $skills): void
    {
        $this->filesystem->dumpFile($this->path($owner), (string) json_encode($skills, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    private function path(string $owner): string
    {
        return $this->directory . '/' . sha1($owner) . '.json';
    }
}
