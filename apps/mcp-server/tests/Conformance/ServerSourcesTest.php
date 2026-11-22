<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** A scan that finds nothing makes every convention test of this directory pass on nothing. */
#[CoversNothing]
final class ServerSourcesTest extends TestCase
{
    public function testTheToolsOfEveryBundleAreScanned(): void
    {
        $keys = array_keys(iterator_to_array(ServerSources::tools()->classes()));

        self::assertGreaterThanOrEqual(ServerSources::MIN_TOOL_CLASSES, \count($keys));
        self::assertGreaterThanOrEqual(4, \count(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'Audit\\'))), 'the audit bundle\'s tools');
        self::assertContains('Product\\SearchProducts', $keys, 'the Sylius tools');
    }

    public function testTheOutputsOfEveryBundleAreScanned(): void
    {
        $classes = ServerSources::outputs()->classes();

        self::assertGreaterThanOrEqual(ServerSources::MIN_OUTPUT_CLASSES, \count($classes));
        foreach (['\\McpAuditBundle\\Output\\', '\\Output\\Customer\\'] as $fragment) {
            self::assertNotSame([], array_filter($classes, static fn (string $class): bool => str_contains($class, $fragment)), $fragment);
        }
    }

    public function testAKeyLeadsBackToItsClass(): void
    {
        $tools = ServerSources::tools();

        foreach ($tools->classes() as $key => $class) {
            self::assertSame($class, $tools->classOf($key), $key);
        }
    }
}
