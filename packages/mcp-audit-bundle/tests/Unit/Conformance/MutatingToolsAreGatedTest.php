<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\MutatingToolsAreGatedTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class MutatingToolsAreGatedTest extends MutatingToolsAreGatedTestCase
{
    protected static function toolClasses(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3) . '/src/Tool/*.php') ?: [] as $file) {
            $class = 'Emagma\\McpAuditBundle\\Tool\\' . basename($file, '.php');
            if (class_exists($class)) {
                yield $class;
            }
        }
    }

    protected static function minimumChecked(): int
    {
        return 1;
    }
}
