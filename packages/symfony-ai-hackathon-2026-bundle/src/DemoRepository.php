<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the tour: one Markdown file per demo, `<position>-<name>.md`, a front matter (title, user,
 * prompts, symfony_ai, code) and the guide as its text. The files are the bundle's own (`demos/`).
 *
 * A file that is not a valid demo stops the tour: these are ours, and a broken one is a bug.
 *
 * @internal
 */
final class DemoRepository
{
    /** @var list<Demo>|null */
    private ?array $demos = null;

    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<Demo> in the order of the tour
     */
    public function all(): array
    {
        if (null !== $this->demos) {
            return $this->demos;
        }

        $demos = [];
        foreach ((new Finder())->files()->in($this->directory)->name('/^\d+-[a-z0-9-]+\.md$/')->depth(0)->sortByName() as $file) {
            $demos[] = self::read($file->getFilename(), $file->getContents());
        }
        usort($demos, static fn (Demo $a, Demo $b): int => $a->position <=> $b->position);

        return $this->demos = $demos;
    }

    public function get(string $name): ?Demo
    {
        foreach ($this->all() as $demo) {
            if ($demo->name === $name) {
                return $demo;
            }
        }

        return null;
    }

    /**
     * The demo after this one in the tour, null after the last.
     */
    public function after(Demo $demo): ?Demo
    {
        $next = false;
        foreach ($this->all() as $candidate) {
            if ($next) {
                return $candidate;
            }
            $next = $candidate === $demo;
        }

        return null;
    }

    private static function read(string $filename, string $text): Demo
    {
        if (1 !== preg_match('/^(\d+)-([a-z0-9-]+)\.md$/', $filename, $named) || 1 !== preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $text, $parts)) {
            throw new \InvalidArgumentException(\sprintf('The demo "%s" has no front matter.', $filename));
        }

        try {
            $front = Yaml::parse($parts[1]);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException(\sprintf('The front matter of the demo "%s" is not valid YAML: %s', $filename, $exception->getMessage()), 0, $exception);
        }
        if (!\is_array($front) || !\is_string($front['title'] ?? null) || '' === trim($front['title'])) {
            throw new \InvalidArgumentException(\sprintf('The demo "%s" has no title.', $filename));
        }

        return new Demo(
            $named[2],
            (int) $named[1],
            trim($front['title']),
            \is_string($front['user'] ?? null) ? $front['user'] : 'admin',
            self::strings($front['prompts'] ?? [], $filename, 'prompts'),
            self::strings($front['symfony_ai'] ?? [], $filename, 'symfony_ai'),
            self::strings($front['code'] ?? [], $filename, 'code'),
            trim($parts[2]),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $filename, string $key): array
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(\sprintf('`%s` of the demo "%s" is a list.', $key, $filename));
        }

        $strings = [];
        foreach ($value as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                throw new \InvalidArgumentException(\sprintf('`%s` of the demo "%s" is a list of texts.', $key, $filename));
            }
            $strings[] = trim($item);
        }

        return $strings;
    }
}
