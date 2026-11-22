<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\IrreversibleToolsTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class IrreversibleToolsTest extends IrreversibleToolsTestCase
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

    /** An erasure is not undone: the values are gone. */
    protected static function transitionVerbs(): array
    {
        return ['forget'];
    }
}
