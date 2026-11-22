<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Testing\Conformance;

use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * A tool that mutated under a `read` declaration would slip through the confirm gate.
 */
abstract class MutatingToolsAreGatedTestCase extends TestCase
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

    public function testEveryMutatingToolDeclaresAGatedAction(): void
    {
        $strict = WriteConfirmationPolicy::secureDefault();
        $ungated = [];
        $mutating = 0;

        foreach (static::toolClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();
            // A tool mutates when its own source calls confirmWrite(); a trait is not a tool.
            if ($reflection->isTrait() || $reflection->isInterface() || false === $file || !str_contains((string) file_get_contents($file), 'confirmWrite(')) {
                continue;
            }
            ++$mutating;
            $permissions = array_map(
                static fn (\ReflectionAttribute $a): Permission => $a->newInstance(),
                $reflection->getAttributes(Permission::class),
            );
            if ($strict->acceptsModelConfirmation($permissions)) {
                $ungated[] = $class;
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $mutating, 'the scan found the write tools: an empty scan would prove nothing');
        self::assertSame([], $ungated, \sprintf(
            "These tools mutate the backend but declare no write/delete permission, so the strict\n"
            . "confirmation policy would let a model confirm them on its own:\n%s",
            implode("\n", $ungated),
        ));
    }
}
