<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Testing\Conformance;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\TestCase;

/**
 * A revert of an irreversible tool's entry must be refused before anything is read. What says a tool is irreversible
 * here is independent of the attribute: its name starts with a transition verb of the backend, or its description
 * tells the model it cannot be undone.
 */
abstract class IrreversibleToolsTestCase extends TestCase
{
    /** What a description says of a change nothing writes back. */
    private const string SAYS_IRREVERSIBLE = '/\bIRREVERSIBLE\b|\bnot revertable\b|\bcannot be reverted\b/i';

    /**
     * Every class of the bundle's tool directories: what is not a tool is skipped.
     *
     * @return iterable<class-string>
     */
    abstract protected static function toolClasses(): iterable;

    /**
     * The first word of the tools that move a state machine or create something (`cancel`, `ship`, `create`…).
     *
     * @return list<string>
     */
    abstract protected static function transitionVerbs(): array;

    /**
     * An empty scan proves nothing: a bundle sets it to the number of subjects it holds.
     */
    protected static function minimumChecked(): int
    {
        return 1;
    }

    public function testEveryToolThatCannotBeUndoneIsIrreversible(): void
    {
        $missing = [];
        $checked = 0;

        foreach (static::toolClasses() as $class) {
            $tool = self::tool($class);
            if (null === $tool) {
                continue;
            }
            $verb = explode('_', $tool->name ?? '', 2)[0];
            $says = null !== $tool->description && 1 === preg_match(self::SAYS_IRREVERSIBLE, $tool->description);
            if (!\in_array($verb, static::transitionVerbs(), true) && !$says) {
                continue;
            }
            ++$checked;
            if (!Irreversible::declaredBy($class)) {
                $missing[] = $class;
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $checked, 'the scan found the irreversible tools: an empty scan would prove nothing');
        self::assertSame([], $missing, \sprintf(
            "These tools cannot be undone (a transition verb, or a description saying so) but are not #[Irreversible]:\n%s",
            implode("\n", $missing),
        ));
    }

    public function testOnlyAWriteToolIsIrreversible(): void
    {
        $reading = [];

        foreach (static::toolClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            if (null === self::tool($class) || [] === $reflection->getAttributes(Irreversible::class)) {
                continue;
            }
            $actions = array_map(static fn (\ReflectionAttribute $a): Action => $a->newInstance()->action, $reflection->getAttributes(Permission::class));
            if (!\in_array(Action::Write, $actions, true) && !\in_array(Action::Delete, $actions, true)) {
                $reading[] = $class;
            }
        }

        self::assertSame([], $reading, "#[Irreversible] on a tool that declares no write:\n" . implode("\n", $reading));
    }

    /**
     * @param class-string $class
     */
    private static function tool(string $class): ?McpTool
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
