<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Script;

use Emagma\McpTasksBundle\Script\TaskFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskFiles::class)]
final class TaskFilesTest extends TestCase
{
    public function testEveryFileOfATaskRunsInStrictModeOnce(): void
    {
        self::assertSame("<?php\n\ndeclare(strict_types=1);\n\ncall('x', []);", TaskFiles::strict("<?php\ncall('x', []);"));
        self::assertSame("<?php\ndeclare(strict_types=1);\ncall('x', []);", TaskFiles::strict("<?php\ndeclare(strict_types=1);\ncall('x', []);"), 'written by the model: kept as it is');
    }
}
