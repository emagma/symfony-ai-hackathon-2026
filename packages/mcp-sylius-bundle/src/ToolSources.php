<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle;

/**
 * Where tool classes live, for what reads them without the registry (convention tests, scripts):
 * one root per owning component.
 */
final readonly class ToolSources
{
    /**
     * @param list<array{dir: string, namespace: string, key: string}> $roots
     */
    public function __construct(
        private array $roots,
    ) {
    }

    /**
     * @param string $key prefix of the keys, e.g. `Extension/Wishlist/`: what tells two roots' files apart
     */
    public static function of(string $dir, string $namespace, string $key = ''): self
    {
        return new self([['dir' => $dir, 'namespace' => $namespace, 'key' => $key]]);
    }

    public function with(self ...$others): self
    {
        $roots = $this->roots;
        foreach ($others as $other) {
            $roots = [...$roots, ...$other->roots];
        }

        return new self($roots);
    }

    /**
     * Keyed by the root's prefix and the path relative to the root (`Order/GetOrders.php`).
     *
     * @param string|null  $namePattern  fnmatch() pattern on the basename, e.g. `Search*.php`
     * @param list<string> $excludedDirs path segments to skip (e.g. `Shared`)
     *
     * @return iterable<string, \SplFileInfo>
     */
    public function files(?string $namePattern = null, array $excludedDirs = []): iterable
    {
        foreach ($this->roots as ['dir' => $base, 'key' => $prefix]) {
            if (!is_dir($base)) {
                continue;
            }

            $found = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }
                if (null !== $namePattern && !fnmatch($namePattern, $file->getFilename())) {
                    continue;
                }
                $relative = str_replace(\DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), \strlen($base) + 1));
                if ([] !== array_intersect($excludedDirs, \array_slice(explode('/', $relative), 0, -1))) {
                    continue;
                }
                $found[$prefix . $relative] = $file;
            }
            ksort($found);

            yield from $found;
        }
    }

    /**
     * Keyed like {@see files()}, without the `.php` and with backslashes (`Order\GetOrders`).
     *
     * @param string|null $namePattern fnmatch() pattern on the file basename
     *
     * @return iterable<string, class-string>
     */
    public function classes(?string $namePattern = null): iterable
    {
        foreach ($this->files($namePattern) as $relative => $file) {
            $short = str_replace('/', '\\', substr($relative, 0, -4));
            $class = $this->classOf($short);
            if (null !== $class) {
                yield $short => $class;
            }
        }
    }

    /**
     * Null for a trait, an interface, or a file that declares no class.
     *
     * @return class-string|null
     */
    public function classOf(string $short): ?string
    {
        // The longest prefix wins, before the root whose keys carry none.
        $roots = $this->roots;
        usort($roots, static fn (array $a, array $b): int => \strlen($b['key']) <=> \strlen($a['key']));

        foreach ($roots as ['namespace' => $namespace, 'key' => $key]) {
            $prefix = str_replace('/', '\\', $key);
            if ('' !== $prefix && !str_starts_with($short, $prefix)) {
                continue;
            }
            $class = $namespace . substr($short, \strlen($prefix));
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }
}
