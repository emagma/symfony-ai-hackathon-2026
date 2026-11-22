<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Testing\Conformance;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\ToolAnnotationsDeriver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

/**
 * A mutating tool claiming to be read-only would make a client skip the approval prompt.
 */
abstract class ToolAnnotationsCoverageTestCase extends TestCase
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

    public function testEveryMcpToolEndsUpAnnotatedAndConsistentWithItsPermissions(): void
    {
        $unannotated = [];
        $lying = [];
        $toolCount = 0;

        foreach (static::toolClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(McpTool::class);
            if ([] === $attributes) {
                continue;
            }
            ++$toolCount;

            $declared = $attributes[0]->newInstance();
            $permissions = array_map(
                static fn (\ReflectionAttribute $attribute): Permission => $attribute->newInstance(),
                $reflection->getAttributes(Permission::class),
            );
            $mutates = array_any(
                $permissions,
                static fn (Permission $permission): bool => Action::Read !== $permission->action,
            );

            $tool = ToolAnnotationsDeriver::derive(
                new Tool($declared->name ?? $class, null, ['type' => 'object'], $declared->description, $declared->annotations),
                $permissions,
            );

            if (null === $tool->annotations) {
                $unannotated[] = $class;

                continue;
            }
            if ($mutates && true === $tool->annotations->readOnlyHint) {
                $lying[] = $class;
            }
            if (!$mutates && false === $tool->annotations->readOnlyHint) {
                $lying[] = $class;
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $toolCount, 'tool discovery looks broken');
        self::assertSame([], $unannotated, "Every tool must end up with annotations:\n" . implode("\n", $unannotated));
        self::assertSame([], $lying, "readOnlyHint contradicts the declared permissions on:\n" . implode("\n", $lying));
    }
}
