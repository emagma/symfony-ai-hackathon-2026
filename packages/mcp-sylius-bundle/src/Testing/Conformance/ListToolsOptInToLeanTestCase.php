<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Testing\Conformance;

use Emagma\McpCoreBundle\Authorization\Deprecated;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\TestCase;

/**
 * The scoped client sends no `Prefer`: a list or search tool that forgets {@see LeanLocaleHeaderTrait}
 * gets full payloads and, where the plugin gates its filters, the unfiltered collection.
 */
abstract class ListToolsOptInToLeanTestCase extends TestCase
{
    abstract protected static function tools(): ToolSources;

    protected static function minimumChecked(): int
    {
        return 1;
    }

    /**
     * Lists that read a full payload on purpose: key of the tool ({@see ToolSources::classes()}) => why.
     *
     * @return array<string, string>
     */
    protected static function fullPayloadByDesign(): array
    {
        return [];
    }

    public function testEveryListToolOptsInToTheLeanRepresentation(): void
    {
        $missing = [];
        $count = 0;

        $lists = [...static::tools()->classes('List*.php'), ...static::tools()->classes('Search*.php')];
        foreach ($lists as $short => $class) {
            if (isset(static::fullPayloadByDesign()[$short])) {
                continue;
            }
            if ([] !== (new \ReflectionClass($class))->getAttributes(Deprecated::class)) {
                continue; // a delegate to its replacement, which is checked itself
            }
            ++$count;
            if (!\in_array(LeanLocaleHeaderTrait::class, class_uses($class) ?: [], true)) {
                $missing[] = $short;
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $count, 'list tool discovery looks broken');
        self::assertSame([], $missing, "These list tools send no lean opt-in (add LeanLocaleHeaderTrait or list them as full by design):\n" . implode("\n", $missing));
    }

    public function testTheFullPayloadListDoesNotNameAToolThatOptsIn(): void
    {
        // A bundle may list none.
        $this->addToAssertionCount(1);

        foreach (array_keys(static::fullPayloadByDesign()) as $short) {
            $class = static::tools()->classOf($short);
            self::assertNotNull($class, $short . ' is not a tool anymore');
            self::assertNotContains(LeanLocaleHeaderTrait::class, class_uses($class) ?: [], $short . ' opts in to lean: drop it from the list');
        }
    }
}
