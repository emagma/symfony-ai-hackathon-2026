<?php

declare(strict_types=1);

namespace Emagma\McpSkillsBundle;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the skills of a directory: one folder per skill, named like the skill, with its SKILL.md.
 *
 * The front matter is the one of Agent Skills (`name`, `description`), with two `metadata` of ours:
 * `version`, and `requires`, the grants of the tools the skill uses (`sylius:order:read ...`).
 * A folder that is not a valid skill is left out and said so by problems(): it never stops the server.
 */
final class SkillRepository
{
    public const string MANIFEST = 'SKILL.md';

    public const string NAME = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const int MAX_FILE_BYTES = 200_000;

    /** @var array<string, Skill>|null */
    private ?array $skills = null;

    /** @var array<string, string> folder => why it is not a skill */
    private array $problems = [];

    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @return array<string, Skill> by name, sorted
     */
    public function all(): array
    {
        if (null !== $this->skills) {
            return $this->skills;
        }

        $this->skills = [];
        $this->problems = [];
        if (!is_dir($this->directory)) {
            return $this->skills;
        }

        foreach ((new Finder())->directories()->in($this->directory)->depth(0)->sortByName() as $folder) {
            try {
                $skill = $this->read($folder->getPathname(), $folder->getFilename());
                $this->skills[$skill->name] = $skill;
            } catch (\InvalidArgumentException $exception) {
                $this->problems[$folder->getFilename()] = $exception->getMessage();
            }
        }

        return $this->skills;
    }

    public function get(string $name): ?Skill
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function problems(): array
    {
        $this->all();

        return $this->problems;
    }

    /**
     * The files are read again on the next call: a skill edited on disk is served without a restart.
     */
    public function forget(): void
    {
        $this->skills = null;
    }

    private function read(string $path, string $folder): Skill
    {
        $manifest = $path . '/' . self::MANIFEST;
        if (!is_file($manifest)) {
            throw new \InvalidArgumentException('no SKILL.md');
        }
        $text = (string) file_get_contents($manifest);
        if (1 !== preg_match('/\A---\R(.*?)\R---\R/s', $text, $match)) {
            throw new \InvalidArgumentException('SKILL.md has no front matter');
        }

        try {
            $front = Yaml::parse($match[1]);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException('SKILL.md front matter is not valid YAML: ' . $exception->getMessage(), 0, $exception);
        }
        if (!\is_array($front)) {
            throw new \InvalidArgumentException('SKILL.md front matter is not a map');
        }

        $name = $front['name'] ?? null;
        if (!\is_string($name) || 1 !== preg_match(self::NAME, $name) || $name !== $folder) {
            throw new \InvalidArgumentException('`name` must be lowercase words joined by hyphens, and the name of its folder');
        }
        $description = $front['description'] ?? null;
        if (!\is_string($description) || '' === trim($description)) {
            throw new \InvalidArgumentException('`description` is missing: it is what tells a model when to use the skill');
        }
        $metadata = \is_array($front['metadata'] ?? null) ? $front['metadata'] : [];
        $version = $metadata['version'] ?? '1';

        $files = [self::MANIFEST => $text];
        foreach ((new Finder())->files()->in($path)->notName(self::MANIFEST)->sortByName() as $file) {
            if ($file->getSize() <= self::MAX_FILE_BYTES) {
                $files[$file->getRelativePathname()] = $file->getContents();
            }
        }

        return new Skill(
            $name,
            trim($description),
            \is_scalar($version) ? (string) $version : '1',
            self::permissions($metadata['requires'] ?? ''),
            $files,
        );
    }

    /**
     * @return list<Permission>
     */
    private static function permissions(mixed $requires): array
    {
        if (!\is_string($requires)) {
            throw new \InvalidArgumentException('`metadata.requires` is a string of grants separated by spaces');
        }

        $permissions = [];
        foreach (preg_split('/[\s,]+/', trim($requires), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $grant) {
            $parts = explode(':', $grant);
            $action = 3 === \count($parts) ? Action::tryFrom($parts[2]) : null;
            if (null === $action || '' === $parts[0] || '' === $parts[1]) {
                throw new \InvalidArgumentException(\sprintf('`metadata.requires`: "%s" is not <domain>:<resource>:<action>', $grant));
            }
            $permissions[] = new Permission($parts[1], $action, $parts[0]);
        }

        return $permissions;
    }
}
