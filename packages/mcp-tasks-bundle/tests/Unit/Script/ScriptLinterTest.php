<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Script;

use Emagma\McpTasksBundle\Script\ScriptInspector;
use Emagma\McpTasksBundle\Script\ScriptLinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;

#[CoversClass(ScriptLinter::class)]
final class ScriptLinterTest extends TestCase
{
    /** The package's own vendor/, or the application's that runs the suite from the monorepo. */
    public static function ecs(): string
    {
        foreach ([__DIR__ . '/../../../vendor/bin/ecs', __DIR__ . '/../../../../../apps/mcp-server/vendor/bin/ecs'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    public const array COMMAND = ['check', '--fix', '--no-progress-bar', '--no-interaction', '--config=' . __DIR__ . '/../../../resources/linter/ecs.php', ScriptLinter::PLACEHOLDER];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-lint-' . bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->directory . '/steps/000000/run.php', "<?php\n\$found = call('search_products', []);\n\$names = array_map(fn (array \$p): string => trim(\$p['name']), \$found['items']);\nif ([] === \$names) {\n    throw new RuntimeException(sprintf('None on page %d.', 1));\n}\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testTheLinterFixesTheFilesOfTheTaskInPlace(): void
    {
        if ('' === self::ecs()) {
            self::markTestSkipped('No ECS binary: run composer install in the package.');
        }

        (new ScriptLinter(self::ecs(), self::COMMAND))->lint($this->directory);

        $fixed = (string) file_get_contents($this->directory . '/steps/000000/run.php');
        self::assertStringContainsString('new \\RuntimeException(\\sprintf(', $fixed);
        self::assertSame(['search_products'], (new ScriptInspector())->manifest([$fixed]));
    }

    public function testALinterThatFailsThrows(): void
    {
        $this->expectException(ProcessFailedException::class);

        (new ScriptLinter('/bin/false', [ScriptLinter::PLACEHOLDER]))->lint($this->directory);
    }

    public function testOnlyTheDirectoryOfTheTaskReplacesThePlaceholder(): void
    {
        $echo = $this->directory . '/echo.sh';
        file_put_contents($echo, "#!/bin/sh\necho \"\$@\" > \"\$2/args\"\n");
        chmod($echo, 0o700);

        (new ScriptLinter($echo, ['--fix', ScriptLinter::PLACEHOLDER, '{other}']))->lint($this->directory);

        self::assertSame("--fix {$this->directory} {other}\n", file_get_contents($this->directory . '/args'));
    }
}
