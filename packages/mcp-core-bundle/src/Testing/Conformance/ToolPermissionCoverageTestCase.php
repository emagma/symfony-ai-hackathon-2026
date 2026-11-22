<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Testing\Conformance;

use Emagma\McpCoreBundle\Authorization\Permission;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\TestCase;

/**
 * A tool without #[Permission] is silently never authorizable (fail-closed): this makes it a failure.
 */
abstract class ToolPermissionCoverageTestCase extends TestCase
{
    /**
     * Every class of the bundle's tool directories: what is not a tool is skipped.
     *
     * @return iterable<class-string>
     */
    abstract protected static function toolClasses(): iterable;

    /**
     * An empty scan proves nothing: a bundle sets it to the number of subjects it holds.
     */
    protected static function minimumChecked(): int
    {
        return 1;
    }

    public function testEveryMcpToolDeclaresAtLeastOnePermission(): void
    {
        $missing = [];
        $toolCount = 0;

        foreach (static::toolClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            if ([] === $reflection->getAttributes(McpTool::class)) {
                continue;
            }
            ++$toolCount;
            if ([] === $reflection->getAttributes(Permission::class)) {
                $missing[] = $class;
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $toolCount, 'tool discovery looks broken');
        self::assertSame([], $missing, \sprintf(
            "Every #[McpTool] class must declare #[Permission] (fail-closed otherwise). Missing:\n%s",
            implode("\n", $missing),
        ));
    }
}
