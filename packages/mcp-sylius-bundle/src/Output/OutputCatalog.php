<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output;

use Emagma\McpCoreBundle\Output\Field;
use Emagma\McpCoreBundle\Output\FieldRegistry;
use Symfony\Component\Finder\Finder;

/**
 * The output DTOs of one or several roots, each given by the component that owns it.
 * A filesystem scan: meant to run once, at container build.
 */
final readonly class OutputCatalog
{
    /**
     * @param list<array{dir: string, namespace: string}> $roots
     */
    public function __construct(
        private array $roots,
    ) {
    }

    public static function of(string $dir, string $namespace): self
    {
        return new self([['dir' => $dir, 'namespace' => $namespace]]);
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
     * @return list<class-string>
     */
    public function classes(): array
    {
        $classes = [];
        foreach ($this->roots as ['dir' => $dir, 'namespace' => $namespace]) {
            foreach ((new Finder())->files()->in($dir)->notPath('Shared')->name('*.php')->sortByName() as $file) {
                $class = $namespace . str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    public function personalKeys(): array
    {
        $keys = [];
        foreach ($this->classes() as $class) {
            foreach (FieldRegistry::with($class, Field::Personal) as $name) {
                $keys[mb_strtolower($name)] = true;
            }
        }

        return array_keys($keys);
    }
}
