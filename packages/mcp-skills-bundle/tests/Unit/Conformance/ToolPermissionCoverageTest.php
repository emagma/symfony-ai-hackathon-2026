<?php

declare(strict_types=1);

namespace Emagma\McpSkillsBundle\Tests\Unit\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\ToolPermissionCoverageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ToolPermissionCoverageTest extends ToolPermissionCoverageTestCase
{
    protected static function toolClasses(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3) . '/src/Tool/*.php') ?: [] as $file) {
            $class = 'Emagma\\McpSkillsBundle\\Tool\\' . basename($file, '.php');
            if (class_exists($class)) {
                yield $class;
            }
        }
    }
}
