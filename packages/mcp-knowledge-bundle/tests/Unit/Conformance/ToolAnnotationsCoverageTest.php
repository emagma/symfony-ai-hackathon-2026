<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Tests\Unit\Conformance;

use Emagma\McpCoreBundle\Testing\Conformance\ToolAnnotationsCoverageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ToolAnnotationsCoverageTest extends ToolAnnotationsCoverageTestCase
{
    protected static function toolClasses(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3) . '/src/Tool/*.php') ?: [] as $file) {
            $class = 'Emagma\\McpKnowledgeBundle\\Tool\\' . basename($file, '.php');
            if (class_exists($class)) {
                yield $class;
            }
        }
    }
}
