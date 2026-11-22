<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Testing\Conformance;

use Emagma\McpCoreBundle\Output\Field;
use Emagma\McpCoreBundle\Output\FieldRegistry;
use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\TestCase;

/**
 * A batch upsert whose resource has #[Untrusted] or #[Identifier] fields names its output DTO, so that the
 * `before` of a diff is marked or tokenised. Linked by directory: `Tool/<Dir>/` serves `Output/<Dir>/`.
 */
abstract class BatchUpsertUntrustedDiffTestCase extends TestCase
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

    public function testEveryUpsertOfAResourceWithUntrustedFieldsNamesItsOutputClass(): void
    {
        $untrustedByDir = [];
        foreach (static::outputs()->classes() as $class) {
            if ([] !== FieldRegistry::with($class, Field::Untrusted) || [] !== FieldRegistry::with($class, Field::Identifier)) {
                $untrustedByDir[self::dirOf($class, 'Output')][] = $class;
            }
        }
        foreach (static::knownDirs() as $dir) {
            self::assertArrayHasKey($dir, $untrustedByDir, 'discovery looks broken');
        }

        $missing = [];
        $wrong = [];
        $checked = 0;
        foreach ($this->upsertClasses() as $class) {
            ++$checked;
            $dir = self::dirOf($class, 'Tool');
            $method = new \ReflectionMethod($class, 'outputClass');
            /** @var class-string|null $declared */
            $declared = $method->invoke(null);

            if (null === $declared) {
                if (isset($untrustedByDir[$dir])) {
                    $missing[] = \sprintf('%s (serves %s)', $class, implode(', ', $untrustedByDir[$dir]));
                }

                continue;
            }
            if ([] === FieldRegistry::with($declared, Field::Untrusted) && [] === FieldRegistry::with($declared, Field::Identifier)) {
                $wrong[] = \sprintf('%s names %s, which has no #[Untrusted] nor #[Identifier] field', $class, $declared);
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $checked, 'upsert discovery looks broken');
        self::assertSame([], $missing, "These batch upserts serve a DTO with #[Untrusted] or #[Identifier] fields and do not override outputClass():\n" . implode("\n", $missing));
        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * @return list<class-string>
     */
    private function upsertClasses(): array
    {
        $classes = [];
        foreach (static::tools()->classes() as $class) {
            if (\in_array(BatchUpsertTrait::class, class_uses($class) ?: [], true)) {
                $classes[] = $class;
            }
        }

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
