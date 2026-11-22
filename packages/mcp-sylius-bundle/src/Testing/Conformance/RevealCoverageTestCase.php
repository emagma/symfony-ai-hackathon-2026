<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Testing\Conformance;

use Emagma\McpCoreBundle\Authorization\RevealsPersonalData;
use Emagma\McpCoreBundle\Output\Field;
use Emagma\McpCoreBundle\Output\FieldRegistry;
use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\ToolSources;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\TestCase;

/**
 * A `get_*` tool whose DTOs (embedded ones included) carry an #[Identifier] or #[Surplus] field takes
 * `reveal` under #[RevealsPersonalData]. A `search_*` never reveals: a page of emails is an exfiltration.
 */
abstract class RevealCoverageTestCase extends TestCase
{
    abstract protected static function tools(): ToolSources;

    abstract protected static function outputs(): OutputCatalog;

    /**
     * Output directories the scan must find: the guard against a scan that finds nothing.
     *
     * @return list<string>
     */
    protected static function knownDirs(): array
    {
        return [];
    }

    protected static function minimumChecked(): int
    {
        return 1;
    }

    public function testEveryGetToolThatRendersIdentifiersTakesRevealAndNoSearchDoes(): void
    {
        $hidingByDir = [];
        foreach (static::outputs()->classes() as $class) {
            if (self::hidesSomething($class)) {
                $hidingByDir[self::dirOf($class, 'Output')][] = $class;
            }
        }
        foreach (static::knownDirs() as $dir) {
            self::assertArrayHasKey($dir, $hidingByDir, 'discovery looks broken: embedded DTOs count');
        }

        $missing = [];
        $pointless = [];
        $searches = [];
        foreach ($this->toolClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(McpTool::class);
            if ([] === $attributes) {
                continue;
            }
            $name = (string) $attributes[0]->newInstance()->name;
            $declares = [] !== $reflection->getAttributes(RevealsPersonalData::class);
            $takesReveal = $reflection->hasMethod('__invoke') &&
                array_any($reflection->getMethod('__invoke')->getParameters(), static fn (\ReflectionParameter $p): bool => 'reveal' === $p->getName());

            if (str_starts_with($name, 'search_') && ($declares || $takesReveal)) {
                $searches[] = $name;
            }
            // Only the batch gets (`get_<resources>(ids[])`) return people; a single-id helper returns figures.
            $first = $reflection->hasMethod('__invoke') ? ($reflection->getMethod('__invoke')->getParameters()[0] ?? null) : null;
            $batchGet = str_starts_with($name, 'get_') &&
                null !== $first && $first->getType() instanceof \ReflectionNamedType && 'array' === $first->getType()->getName();
            if (!$batchGet) {
                continue;
            }
            $hides = isset($hidingByDir[self::dirOf($class, 'Tool')]);
            if ($hides && !($declares && $takesReveal)) {
                $missing[] = $name;
            }
            if (!$hides && ($declares || $takesReveal)) {
                $pointless[] = $name;
            }
        }

        self::assertSame([], $missing, "These get tools render identifiers or surplus data and must take `reveal` under #[RevealsPersonalData]:\n" . implode("\n", $missing));
        self::assertSame([], $pointless, "These get tools declare a reveal with nothing to reveal:\n" . implode("\n", $pointless));
        self::assertSame([], $searches, "A search tool never reveals:\n" . implode("\n", $searches));
    }

    /**
     * @param class-string $class
     */
    private static function hidesSomething(string $class, int $depth = 0): bool
    {
        if ([] !== FieldRegistry::with($class, Field::Identifier) || [] !== FieldRegistry::with($class, Field::Surplus)) {
            return true;
        }
        if ($depth > 3) {
            return false;
        }
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                /** @var class-string $embedded */
                $embedded = $type->getName();
                if (class_exists($embedded) && self::hidesSomething($embedded, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function toolClasses(): array
    {
        $classes = iterator_to_array(static::tools()->classes(), false);

        return $classes;
    }

    private static function dirOf(string $class, string $layer): string
    {
        $parts = explode('\\', $class);
        $at = array_search($layer, $parts, true);
        \assert(false !== $at);
        $dir = $parts[$at + 1];

        // `Emagma\Mcp<Name>Bundle\…`: another bundle's directories are prefixed with its name (`Audit/…`, `SyliusCms/…`).
        $bundle = (string) preg_replace('/^Mcp(.+)Bundle$/', '$1', $parts[1] ?? '');

        return 'Sylius' === $bundle ? $dir : $bundle . '/' . $dir;
    }
}
